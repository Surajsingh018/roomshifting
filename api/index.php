<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/bootstrap.php';

$route = trim((string)($_GET['route'] ?? ''), '/');
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

// Public catalog reads use the repository and return database-backed records.
if ($method === 'GET') {
    $cmsAction = null;
    $slug = null;
    if ($route === 'services') $cmsAction = 'services';
    elseif (preg_match('~^services/([^/]+)$~', $route, $matches)) { $cmsAction = 'service'; $slug = rawurldecode($matches[1]); }
    elseif ($route === 'service-areas') $cmsAction = 'service-areas';
    elseif (preg_match('~^pages/([^/]+)$~', $route, $matches)) { $cmsAction = 'page'; $slug = rawurldecode($matches[1]); }
    elseif ($route === 'faqs') $cmsAction = 'faqs';
    elseif ($route === 'banners') $cmsAction = 'banners';
    elseif ($route === 'offers') $cmsAction = 'offers';

    if ($cmsAction !== null) {
        api_method(['GET']);
        require_once dirname(__DIR__) . '/backend/repositories/CmsRepository.php';
        $repository = new CmsRepository(api_db());
        $filters = $_GET;
        if (($cmsAction === 'services' || $cmsAction === 'service-areas')
            && isset($filters['status']) && is_string($filters['status']) && $filters['status'] !== 'active') {
            $user = api_require_admin(api_db());
            api_require_role($user, ['admin', 'staff']);
        }
        switch ($cmsAction) {
            case 'services': api_success($repository->listServices($filters));
            case 'service':
                $service = $repository->findService((string)$slug);
                if ($service === null) api_error(404, 'NOT_FOUND', 'Service not found.');
                api_success($service);
            case 'service-areas': api_success($repository->listServiceAreas($filters));
            case 'page':
                $page = $repository->findPage((string)$slug);
                if ($page === null) api_error(404, 'NOT_FOUND', 'Page not found.');
                api_success($page);
            case 'faqs': api_success($repository->listFaqs($filters));
            case 'banners': api_success($repository->listBanners($filters));
            case 'offers': api_success($repository->listOffers($filters));
        }
    }

    if (preg_match('~^quotes/(\d+)$~', $route, $matches)) {
        api_method(['GET']);
        require_once dirname(__DIR__) . '/backend/repositories/PricingRepository.php';
        $repository = new PricingRepository(api_db());
        $token = $_GET['token'] ?? '';
        if (is_string($token) && $token !== '') {
            if (!$repository->quoteCapabilityMatches((int)$matches[1], $token)) api_error(404, 'NOT_FOUND', 'Quote not found.');
        } else {
            $user = api_require_admin(api_db());
            api_require_role($user, ['admin', 'staff']);
        }
        $quote = $repository->findQuote((int)$matches[1]);
        if ($quote === null) api_error(404, 'NOT_FOUND', 'Quote not found.');
        api_success($quote);
    }
}

if ($route === 'enquiries' && $method === 'POST') {
    api_method(['POST']);
    api_rate_limit('public-enquiry', 10, 900);
    require_once dirname(__DIR__) . '/backend/repositories/PricingRepository.php';
    require_once dirname(__DIR__) . '/backend/services/PricingEngine.php';
    $engine = new PricingEngine();
    $enquiry = $engine->validateEnquiry(api_input());
    $created = (new PricingRepository(api_db()))->createEnquiry($enquiry);
    $created['enquiry_token'] = api_public_capability('enquiry', (int)$created['id'], $enquiry['idempotency_key']);
    api_success($created, $created['duplicate'] ? 200 : 201);
}

if ($route === 'quotes/calculate' && $method === 'POST') {
    api_method(['POST']);
    api_rate_limit('quote-calculation', 20, 900);
    $input = api_input();
    foreach (['total', 'subtotal', 'gst', 'base_price', 'per_km', 'per_floor', 'packing_charges', 'gst_percent', 'min_charge'] as $untrustedPrice) {
        if (array_key_exists($untrustedPrice, $input)) api_error(422, 'CLIENT_PRICING_FORBIDDEN', 'Quote totals and pricing rates are calculated by the server.');
    }
    foreach (array_keys($input) as $field) {
        if (!in_array($field, ['enquiry_id', 'enquiry_token'], true)) api_error(422, 'VALIDATION_ERROR', 'Only enquiry_id and enquiry_token may be submitted to calculate a quote.', [$field => 'Unexpected field.']);
    }
    $enquiryId = filter_var($input['enquiry_id'] ?? null, FILTER_VALIDATE_INT);
    $enquiryToken = $input['enquiry_token'] ?? null;
    if ($enquiryId === false || $enquiryId < 1 || !is_string($enquiryToken) || !preg_match('/^[a-f0-9]{64}$/', $enquiryToken)) api_error(422, 'VALIDATION_ERROR', 'A positive enquiry_id and valid enquiry_token are required.');
    require_once dirname(__DIR__) . '/backend/repositories/PricingRepository.php';
    require_once dirname(__DIR__) . '/backend/services/PricingEngine.php';
    $db = api_db();
    $repository = new PricingRepository($db);
    $engine = new PricingEngine();
    $db->beginTransaction();
    try {
        $enquiry = $repository->enquiryForUpdate((int)$enquiryId);
        if ($enquiry === null) {
            $db->rollBack();
            api_error(404, 'NOT_FOUND', 'Enquiry not found.');
        }
        if (!$repository->enquiryCapabilityMatches((int)$enquiryId, $enquiryToken)) {
            $db->rollBack();
            api_error(404, 'NOT_FOUND', 'Enquiry not found.');
        }
        $rule = $repository->pricingRule((int)$enquiry['service_id']);
        if ($rule === null) {
            $db->rollBack();
            api_error(409, 'PRICING_RULE_UNAVAILABLE', 'Pricing is not configured for this service.');
        }
        $breakdown = $engine->calculate($rule, $enquiry);
        $quote = $repository->saveQuote((int)$enquiryId, $breakdown);
        $db->commit();
        $enquiryKey = $repository->enquiryIdempotencyKey((int)$enquiryId);
        if ($enquiryKey === null) throw new RuntimeException('Enquiry key was unavailable after quote calculation.');
        api_success(['quote_id' => $quote['id'], 'enquiry_id' => (int)$enquiryId, 'quote_token' => api_public_capability('quote', (int)$quote['id'], $enquiryKey), 'valid_until' => $quote['valid_until'], 'breakdown' => $breakdown]);
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
}

if ($route === 'bookings' && $method === 'POST') {
    api_method(['POST']);
    api_rate_limit('public-booking', 6, 900);
    $input = api_input();
    foreach (array_keys($input) as $field) {
        if (!in_array($field, ['quote_id', 'quote_token', 'idempotency_key'], true)) api_error(422, 'VALIDATION_ERROR', 'Only quote_id, quote_token, and idempotency_key may be submitted.', [$field => 'Unexpected field.']);
    }
    $quoteId = filter_var($input['quote_id'] ?? null, FILTER_VALIDATE_INT);
    $quoteToken = $input['quote_token'] ?? null;
    $key = $input['idempotency_key'] ?? null;
    if ($quoteId === false || $quoteId < 1 || !is_string($quoteToken) || !preg_match('/^[a-f0-9]{64}$/', $quoteToken) || !is_string($key) || !preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $key)) api_error(422, 'VALIDATION_ERROR', 'A positive quote_id, valid quote_token, and idempotency_key are required.');
    require_once dirname(__DIR__) . '/backend/repositories/BookingRepository.php';
    $created = (new BookingRepository(api_db()))->createFromQuote((int)$quoteId, $key, $quoteToken);
    api_success($created, $created['duplicate'] ? 200 : 201);
}

if ($route === 'bookings' && $method === 'GET') {
    api_method(['GET']);
    $admin = api_require_admin(api_db());
    api_require_role($admin, ['admin', 'staff']);
    require_once dirname(__DIR__) . '/backend/repositories/BookingRepository.php';
    api_success((new BookingRepository(api_db()))->list($_GET));
}

if ($route === 'bookings/track' && $method === 'GET') {
    api_method(['GET']);
    api_rate_limit('public-booking-track', 60, 60);
    $code = $_GET['code'] ?? '';
    if (!is_string($code) || !preg_match('/^[A-F0-9]{16}$/', $code)) api_error(422, 'VALIDATION_ERROR', 'Provide the tracking code received with your booking.');
    require_once dirname(__DIR__) . '/backend/repositories/BookingRepository.php';
    $tracking = (new BookingRepository(api_db()))->trackByCode($code);
    if ($tracking === null) api_error(404, 'NOT_FOUND', 'Tracking record not found.');
    api_success($tracking);
}

if (preg_match('~^bookings/(\d+)/track$~', $route, $matches) && $method === 'GET') {
    api_method(['GET']);
    api_rate_limit('public-booking-track', 60, 60);
    $code = $_GET['code'] ?? '';
    if (!is_string($code) || !preg_match('/^[A-F0-9]{16}$/', $code)) api_error(422, 'VALIDATION_ERROR', 'Provide the tracking code received with your booking.');
    require_once dirname(__DIR__) . '/backend/repositories/BookingRepository.php';
    $tracking = (new BookingRepository(api_db()))->trackById((int)$matches[1], $code);
    if ($tracking === null) api_error(404, 'NOT_FOUND', 'Tracking record not found.');
    api_success($tracking);
}

if (preg_match('~^bookings/(\d+)/status$~', $route, $matches) && $method === 'PATCH') {
    api_method(['PATCH']);
    $user = api_require_admin(api_db());
    api_require_role($user, ['admin']);
    $input = api_input();
    $status = $input['status'] ?? null;
    $note = $input['note'] ?? null;
    if (!is_string($status) || ($note !== null && (!is_string($note) || mb_strlen($note) > 500))) api_error(422, 'VALIDATION_ERROR', 'Provide a valid status and optional note up to 500 characters.');
    require_once dirname(__DIR__) . '/backend/repositories/BookingRepository.php';
    api_success((new BookingRepository(api_db()))->transition((int)$matches[1], $status, (int)$user['id'], $note));
}

if (preg_match('~^bookings/(\d+)/assignment$~', $route, $matches) && $method === 'PATCH') {
    api_method(['PATCH']);
    $user = api_require_admin(api_db());
    api_require_role($user, ['admin']);
    $input = api_input();
    $staffId = filter_var($input['staff_id'] ?? null, FILTER_VALIDATE_INT);
    $note = $input['note'] ?? null;
    if ($staffId === false || $staffId < 1 || ($note !== null && (!is_string($note) || mb_strlen($note) > 500))) api_error(422, 'VALIDATION_ERROR', 'Provide an active staff_id and optional note up to 500 characters.');
    require_once dirname(__DIR__) . '/backend/repositories/BookingRepository.php';
    api_success((new BookingRepository(api_db()))->assignStaff((int)$matches[1], (int)$staffId, (int)$user['id'], $note));
}

if (preg_match('~^bookings/(\d+)/tracking-events$~',$route,$matches)&&$method==='POST'){
    api_method(['POST']);$user=api_require_admin(api_db());api_require_role($user,['admin','staff']);$input=api_input();
    $type=$input['event_type']??'';$location=$input['location_label']??null;$note=$input['note']??null;$proof=$input['proof_path']??null;
    if(!is_string($type)||($location!==null&&!is_string($location))||($note!==null&&!is_string($note))||($proof!==null&&!is_string($proof)))api_error(422,'VALIDATION_ERROR','Tracking event input is invalid.');
    require_once dirname(__DIR__).'/backend/repositories/BookingRepository.php';
    api_success((new BookingRepository(api_db()))->recordTrackingEvent((int)$matches[1],(int)$user['id'],$type,$location,$note,$proof),201);
}

if ($route === 'payments' && $method === 'POST') {
    api_method(['POST']); api_rate_limit('public-payment-init', 8, 900);
    $input = api_input();
    foreach (array_keys($input) as $field) if (!in_array($field,['booking_id','tracking_code','idempotency_key','method'],true)) api_error(422,'VALIDATION_ERROR','Unexpected payment field.');
    $bookingId=filter_var($input['booking_id']??null,FILTER_VALIDATE_INT);
    $code=$input['tracking_code']??''; $key=$input['idempotency_key']??''; $paymentMethod=$input['method']??'';
    if ($bookingId===false || $bookingId<1 || !is_string($code) || !preg_match('/^[A-F0-9]{16}$/',$code) || !is_string($key) || !preg_match('/^[A-Za-z0-9._:-]{8,128}$/',$key) || !is_string($paymentMethod)) api_error(422,'VALIDATION_ERROR','Provide booking_id, tracking_code, idempotency_key, and method.');
    require_once dirname(__DIR__) . '/backend/repositories/OperationalRepository.php';
    $result=(new OperationalRepository(api_db()))->createPayment((int)$bookingId,$code,$key,$paymentMethod);
    api_success($result,$result['duplicate']?200:201);
}

if ($route === 'payments/webhook' && $method === 'POST') {
    api_method(['POST']); api_rate_limit('payment-webhook',120,60);
    $raw=(string)file_get_contents('php://input'); $input=json_decode($raw,true);
    if (!is_array($input) || json_last_error()!==JSON_ERROR_NONE) api_error(400,'INVALID_JSON','Request body must be a valid JSON object.');
    foreach (array_keys($input) as $field) if (!in_array($field,['event_id','payment_reference','status'],true)) api_error(422,'VALIDATION_ERROR','Unexpected payment webhook field.');
    foreach (['event_id','payment_reference','status'] as $field) if (!isset($input[$field]) || !is_string($input[$field]) || trim($input[$field]) === '' || mb_strlen($input[$field]) > 190) api_error(422,'VALIDATION_ERROR','Webhook event_id, payment_reference, and status are required strings.');
    require_once dirname(__DIR__) . '/backend/repositories/OperationalRepository.php';
    $result=(new OperationalRepository(api_db()))->applyWebhook((string)($input['event_id']??''),(string)($input['payment_reference']??''),(string)($input['status']??''),$raw);
    api_success($result);
}

if ($route === 'payments' && $method === 'GET') {
    api_method(['GET']); $user=api_require_admin(api_db()); api_require_role($user,['admin','staff']);
    require_once dirname(__DIR__) . '/backend/repositories/OperationalRepository.php';
    api_success((new OperationalRepository(api_db()))->payments());
}

if ($route === 'reviews' && $method === 'GET') {
    api_method(['GET']); $all=($_GET['moderation']??'')==='1';
    if ($all) { $user=api_require_admin(api_db()); api_require_role($user,['admin','staff']); }
    require_once dirname(__DIR__) . '/backend/repositories/OperationalRepository.php';
    api_success((new OperationalRepository(api_db()))->reviews($all));
}

if ($route === 'reviews' && $method === 'POST') {
    api_method(['POST']); api_rate_limit('public-review',5,3600); $input=api_input();
    $bookingId=filter_var($input['booking_id']??null,FILTER_VALIDATE_INT); $rating=filter_var($input['rating']??null,FILTER_VALIDATE_INT);
    $code=$input['tracking_code']??''; $key=$input['idempotency_key']??''; $comment=$input['comment']??'';
    if ($bookingId===false||$bookingId<1||$rating===false||!is_string($code)||!preg_match('/^[A-F0-9]{16}$/',$code)||!is_string($key)||!preg_match('/^[A-Za-z0-9._:-]{8,128}$/',$key)||!is_string($comment)) api_error(422,'VALIDATION_ERROR','Review input is invalid.');
    require_once dirname(__DIR__) . '/backend/repositories/OperationalRepository.php';
    api_success((new OperationalRepository(api_db()))->createReview((int)$bookingId,$code,(int)$rating,$comment,$key),201);
}

if (preg_match('~^reviews/(\d+)/moderation$~',$route,$matches) && $method==='PATCH') {
    api_method(['PATCH']); $user=api_require_admin(api_db()); api_require_role($user,['admin']); $input=api_input();
    $status=$input['status']??''; $note=$input['note']??null;
    if (!is_string($status)||($note!==null&&(!is_string($note)||mb_strlen($note)>500))) api_error(422,'VALIDATION_ERROR','Review moderation input is invalid.');
    require_once dirname(__DIR__) . '/backend/repositories/OperationalRepository.php';
    api_success((new OperationalRepository(api_db()))->moderateReview((int)$matches[1],$status,(int)$user['id'],$note));
}

if ($route === 'notifications' && in_array($method,['GET','POST'],true)) {
    api_method(['GET','POST']); $user=api_require_admin(api_db()); api_require_role($user,['admin','staff']);
    require_once dirname(__DIR__) . '/backend/repositories/OperationalRepository.php'; $repository=new OperationalRepository(api_db());
    if ($method==='GET') api_success($repository->notifications());
    $input=api_input(); $recipient=$input['recipient']??''; $channel=$input['channel']??'internal'; $template=$input['template_key']??''; $payload=$input['payload']??[]; $bookingId=isset($input['booking_id'])?filter_var($input['booking_id'],FILTER_VALIDATE_INT):null;
    if (!is_string($recipient)||!is_string($channel)||!is_string($template)||!is_array($payload)||($bookingId!==null&&($bookingId===false||$bookingId<1))) api_error(422,'VALIDATION_ERROR','Notification input is invalid.');
    api_success(['id'=>$repository->queueNotification($recipient,$channel,$template,$payload,$bookingId===null?null:(int)$bookingId),'status'=>'queued'],201);
}

$routes = [
    'auth/login' => dirname(__DIR__) . '/backend/api/auth/login.php',
    'auth/logout' => dirname(__DIR__) . '/backend/api/auth/logout.php',
    'services' => dirname(__DIR__) . '/backend/api/services.php',
    'enquiries' => dirname(__DIR__) . '/backend/api/enquiries.php',
];
if (!isset($routes[$route])) roomshift_api_error(404, 'NOT_FOUND', 'API route not found.');
require $routes[$route];
