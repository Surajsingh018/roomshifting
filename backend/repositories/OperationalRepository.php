<?php
declare(strict_types=1);

/** Persistence for operational payments, notification queue records, and reviews. */
final class OperationalRepository
{
    public function __construct(private PDO $pdo) {}

    public function createPayment(int $bookingId, string $trackingCode, string $key, string $method): array
    {
        if (!in_array($method, ['cod','online'], true)) api_error(422, 'VALIDATION_ERROR', 'Payment method must be cod or online.');
        $this->pdo->beginTransaction();
        try {
            $existing = $this->pdo->prepare('SELECT id,booking_id,method,amount,status FROM payments WHERE idempotency_key=? LIMIT 1');
            $existing->execute([$key]);
            $payment = $existing->fetch();
            if ($payment) {
                if ((int)$payment['booking_id'] !== $bookingId || $payment['method'] !== $method) {
                    $this->pdo->rollBack();
                    api_error(409, 'IDEMPOTENCY_CONFLICT', 'This payment key was already used with different booking or method details.');
                }
                $this->pdo->commit();
                return $this->paymentResponse($payment, true);
            }
            $bookingQuery = $this->pdo->prepare('SELECT id,total,status,tracking_code FROM bookings WHERE id=? AND tracking_code=? FOR UPDATE');
            $bookingQuery->execute([$bookingId,$trackingCode]);
            $booking = $bookingQuery->fetch();
            if (!$booking) { $this->pdo->rollBack(); api_error(404,'NOT_FOUND','Booking not found.'); }
            if (in_array($booking['status'], ['cancelled'], true)) { $this->pdo->rollBack(); api_error(409,'BOOKING_CLOSED','Payment cannot be created for a cancelled booking.'); }
            $stmt = $this->pdo->prepare("INSERT INTO payments (booking_id,idempotency_key,method,provider,amount,status,metadata) VALUES (?,?,?, ?,?,'pending',?)");
            $provider = $method === 'online' ? (getenv('ROOMSHIFT_PAYMENT_PROVIDER') ?: null) : null;
            // A provider name alone is not an integration; online payments remain pending.
            $providerReady = false;
            $metadata = json_encode(['provider_enabled' => $providerReady], JSON_THROW_ON_ERROR);
            $stmt->execute([$bookingId,$key,$method,$provider,$booking['total'],$metadata]);
            $result = ['id'=>(int)$this->pdo->lastInsertId(),'booking_id'=>$bookingId,'method'=>$method,'amount'=>$booking['total'],'status'=>'pending','provider_configured'=>$providerReady,'duplicate'=>false];
            $this->pdo->commit();
            return $result;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function applyWebhook(string $eventId, string $paymentReference, string $status, string $raw): array
    {
        if (!in_array($status,['authorized','paid','failed','refunded'],true)) api_error(422,'VALIDATION_ERROR','Unsupported payment status.');
        $secret = getenv('ROOMSHIFT_PAYMENT_WEBHOOK_SECRET') ?: '';
        if ($secret === '') api_error(503,'PROVIDER_NOT_CONFIGURED','Payment webhook verification is not configured.');
        $signature = $_SERVER['HTTP_X_ROOMSHIFT_SIGNATURE'] ?? '';
        $expected = hash_hmac('sha256',$raw,$secret);
        if (!is_string($signature) || !hash_equals($expected,$signature)) api_error(401,'INVALID_SIGNATURE','Webhook signature is invalid.');
        $this->pdo->beginTransaction();
        try {
            $existing = $this->pdo->prepare('SELECT id,status FROM payments WHERE provider_event_id=? LIMIT 1 FOR UPDATE');
            $existing->execute([$eventId]);
            $prior = $existing->fetch();
            if ($prior) { $this->pdo->commit(); return ['payment_id'=>(int)$prior['id'],'status'=>$prior['status'],'duplicate'=>true]; }
            $stmt = $this->pdo->prepare('UPDATE payments SET status=?,provider_reference=?,provider_event_id=?,webhook_signature=? WHERE provider_reference=?');
            $stmt->execute([$status,$paymentReference,$eventId,$signature,$paymentReference]);
            if ($stmt->rowCount() !== 1) { $this->pdo->rollBack(); api_error(404,'NOT_FOUND','Payment reference not found.'); }
            $id = $this->pdo->prepare('SELECT id FROM payments WHERE provider_event_id=?');
            $id->execute([$eventId]);
            $paymentId = (int)$id->fetchColumn();
            $this->pdo->commit();
            return ['payment_id'=>$paymentId,'status'=>$status,'duplicate'=>false];
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function payments(): array
    {
        return $this->pdo->query('SELECT id,booking_id,method,provider,amount,currency,status,provider_reference,created_at FROM payments ORDER BY id DESC LIMIT 200')->fetchAll();
    }

    public function createReview(int $bookingId, string $trackingCode, int $rating, string $comment, string $key): array
    {
        if ($rating < 1 || $rating > 5 || mb_strlen($comment) > 3000) api_error(422,'VALIDATION_ERROR','Rating must be 1-5 and comment must be at most 3000 characters.');
        $check = $this->pdo->prepare("SELECT id,status FROM bookings WHERE id=? AND tracking_code=? LIMIT 1");
        $check->execute([$bookingId,$trackingCode]);
        $booking = $check->fetch();
        if (!$booking) api_error(404,'NOT_FOUND','Booking not found.');
        if ($booking['status'] !== 'completed') api_error(409,'BOOKING_NOT_COMPLETED','A review can be submitted after completion.');
        $stmt = $this->pdo->prepare("INSERT INTO reviews (booking_id,rating,comment,status,idempotency_key) VALUES (?,?,?,'pending',?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)");
        $stmt->execute([$bookingId,$rating,$comment,$key]);
        $id = (int)$this->pdo->lastInsertId();
        $read = $this->pdo->prepare('SELECT id,booking_id,rating,comment,status,created_at FROM reviews WHERE id=?');
        $read->execute([$id]);
        $review = $read->fetch();
        $review['duplicate'] = (int)$stmt->rowCount() === 0;
        return $review;
    }

    public function moderateReview(int $reviewId, string $status, int $adminId, ?string $note): array
    {
        if (!in_array($status,['approved','rejected'],true)) api_error(422,'VALIDATION_ERROR','Review status must be approved or rejected.');
        $stmt = $this->pdo->prepare('UPDATE reviews SET status=?,moderation_note=?,moderated_by=?,moderated_at=NOW() WHERE id=?');
        $stmt->execute([$status,$note,$adminId,$reviewId]);
        if ($stmt->rowCount() === 0) {
            $exists=$this->pdo->prepare('SELECT id FROM reviews WHERE id=?');$exists->execute([$reviewId]);
            if (!$exists->fetchColumn()) api_error(404,'NOT_FOUND','Review not found.');
        }
        return ['id'=>$reviewId,'status'=>$status];
    }

    public function reviews(bool $all = false): array
    {
        $sql = 'SELECT r.id,r.booking_id,r.rating,r.comment,r.status,r.moderation_note,r.created_at FROM reviews r' . ($all ? '' : " WHERE r.status='approved'") . ' ORDER BY r.created_at DESC LIMIT 100';
        return $this->pdo->query($sql)->fetchAll();
    }

    public function queueNotification(string $recipient, string $channel, string $template, array $payload, ?int $bookingId = null): int
    {
        if (!in_array($channel,['email','sms','push','internal'],true)) api_error(422,'VALIDATION_ERROR','Unsupported notification channel.');
        if (mb_strlen($recipient)>190 || !preg_match('/^[a-z0-9_.-]{1,100}$/i',$template)) api_error(422,'VALIDATION_ERROR','Invalid notification recipient or template.');
        $stmt=$this->pdo->prepare("INSERT INTO notifications (booking_id,recipient,channel,template_key,payload,status) VALUES (?,?,?,?,?,'queued')");
        $stmt->execute([$bookingId,$recipient,$channel,$template,json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)]);
        return (int)$this->pdo->lastInsertId();
    }

    public function notifications(): array
    {
        $items=$this->pdo->query('SELECT id,booking_id,recipient,channel,template_key,payload,status,attempts,available_at,last_error,created_at FROM notifications ORDER BY id DESC LIMIT 200')->fetchAll();
        foreach ($items as &$item) $item['payload']=json_decode((string)$item['payload'],true,512,JSON_THROW_ON_ERROR);
        return $items;
    }

    private function paymentResponse(array $payment, bool $duplicate): array
    {
        return ['id'=>(int)$payment['id'],'booking_id'=>(int)$payment['booking_id'],'method'=>$payment['method'],'amount'=>$payment['amount'],'status'=>$payment['status'],'duplicate'=>$duplicate];
    }
}
