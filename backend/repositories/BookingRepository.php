<?php
declare(strict_types=1);

/** Transactional booking state machine and tracking persistence. */
final class BookingRepository
{
    private const TRANSITIONS = [
        'enquiry' => ['quote', 'cancelled'],
        'quote' => ['confirmed', 'cancelled'],
        'confirmed' => ['assigned', 'cancelled'],
        'assigned' => ['in_transit', 'cancelled'],
        'in_transit' => ['completed', 'cancelled'],
        'completed' => [],
        'cancelled' => [],
    ];

    public function __construct(private PDO $pdo) {}

    public function createFromQuote(int $quoteId, string $idempotencyKey, string $quoteToken): array
    {
        try {
            $this->pdo->beginTransaction();
            $quote = $this->pdo->prepare('SELECT q.id, q.enquiry_id, q.total, q.status AS quote_status, q.valid_until, e.name, e.email, e.phone, e.idempotency_key FROM quotes q LEFT JOIN enquiries e ON e.id=q.enquiry_id WHERE q.id=? FOR UPDATE');
            $quote->execute([$quoteId]);
            $row = $quote->fetch();
            if (!$row) {
                $this->pdo->rollBack();
                api_error(404, 'NOT_FOUND', 'Quote not found.');
            }
            if (!is_string($row['idempotency_key'] ?? null)
                || !hash_equals(api_public_capability('quote', $quoteId, $row['idempotency_key']), $quoteToken)) {
                $this->pdo->rollBack();
                api_error(404, 'NOT_FOUND', 'Quote not found.');
            }
            $existing = $this->pdo->prepare('SELECT id, booking_ref, tracking_code, status, quote_id FROM bookings WHERE idempotency_key=? LIMIT 1 FOR UPDATE');
            $existing->execute([$idempotencyKey]);
            $prior = $existing->fetch();
            if ($prior) {
                if ((int)$prior['quote_id'] !== $quoteId) {
                    $this->pdo->rollBack();
                    api_error(409, 'IDEMPOTENCY_CONFLICT', 'This idempotency key was already used for a different quote.');
                }
                $this->pdo->commit();
                return $this->created($prior, true);
            }
            if (in_array($row['quote_status'], ['expired','rejected'], true) || ($row['valid_until'] !== null && strtotime((string)$row['valid_until']) < time())) {
                $this->pdo->rollBack();
                api_error(409, 'QUOTE_EXPIRED', 'This quote is no longer valid.');
            }
            if (empty($row['name']) || empty($row['phone'])) {
                $this->pdo->rollBack();
                api_error(409, 'CUSTOMER_DETAILS_MISSING', 'The quote does not have complete enquiry details.');
            }

            $bookingRef = 'RS-' . strtoupper(bin2hex(random_bytes(5)));
            $trackingCode = strtoupper(bin2hex(random_bytes(8)));
            $stmt = $this->pdo->prepare("INSERT INTO bookings (quote_id,enquiry_id,booking_ref,idempotency_key,tracking_code,customer_name,customer_email,customer_phone,status,total) VALUES (?,?,?,?,?,?,NULLIF(?,''),?, 'quote', ?)");
            $stmt->execute([$quoteId, $row['enquiry_id'], $bookingRef, $idempotencyKey, $trackingCode, $row['name'], $row['email'] ?? '', $row['phone'], $row['total']]);
            $id = (int)$this->pdo->lastInsertId();
            $history = $this->pdo->prepare("INSERT INTO booking_status_history (booking_id,from_status,to_status,note) VALUES (?,NULL,'quote','Booking created from quote')");
            $history->execute([$id]);
            $this->queueCustomerNotification($id, $row['email'] ?? '', $row['phone'], 'booking_created', ['booking_ref'=>$bookingRef,'status'=>'quote']);
            $event=$this->pdo->prepare("INSERT INTO tracking_events (booking_id,event_type,status,note) VALUES (?,'status_changed','quote','Booking created from a valid quote')");$event->execute([$id]);
            $this->pdo->commit();
            return ['id' => $id, 'booking_ref' => $bookingRef, 'tracking_code' => $trackingCode, 'status' => 'quote', 'duplicate' => false];
        } catch (PDOException $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            if (($error->errorInfo[1] ?? null) === 1062) {
                $existing = $this->pdo->prepare('SELECT id,booking_ref,tracking_code,status,quote_id FROM bookings WHERE idempotency_key=? LIMIT 1');
                $existing->execute([$idempotencyKey]);
                $row = $existing->fetch();
                if ($row) {
                    if ((int)$row['quote_id'] !== $quoteId) api_error(409, 'IDEMPOTENCY_CONFLICT', 'This idempotency key was already used for a different quote.');
                    return $this->created($row, true);
                }
            }
            throw $error;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function transition(int $bookingId, string $to, int $adminId, ?string $note = null): array
    {
        if (!array_key_exists($to, self::TRANSITIONS)) api_error(422, 'VALIDATION_ERROR', 'Unknown booking status.');
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('SELECT id,status FROM bookings WHERE id=? FOR UPDATE');
            $stmt->execute([$bookingId]);
            $booking = $stmt->fetch();
            if (!$booking) {
                $this->pdo->rollBack();
                api_error(404, 'NOT_FOUND', 'Booking not found.');
            }
            $from = (string)$booking['status'];
            if (!in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
                $this->pdo->rollBack();
                api_error(409, 'INVALID_TRANSITION', "Booking cannot transition from {$from} to {$to}.", ['from' => $from, 'to' => $to]);
            }
            $update = $this->pdo->prepare('UPDATE bookings SET status=? WHERE id=?');
            $update->execute([$to, $bookingId]);
            $history = $this->pdo->prepare('INSERT INTO booking_status_history (booking_id,from_status,to_status,changed_by,note) VALUES (?,?,?,?,?)');
            $history->execute([$bookingId, $from, $to, $adminId, $note]);
            $event=$this->pdo->prepare("INSERT INTO tracking_events (booking_id,event_type,status,note,created_by) VALUES (?,'status_changed',?,?,?)");$event->execute([$bookingId,$to,$note,$adminId]);
            $customer=$this->pdo->prepare('SELECT customer_email,customer_phone,booking_ref FROM bookings WHERE id=?');$customer->execute([$bookingId]);$contact=$customer->fetch();$this->queueCustomerNotification($bookingId,$contact['customer_email']??'',$contact['customer_phone'],'booking_status_changed',['booking_ref'=>$contact['booking_ref'],'status'=>$to]);
            $this->pdo->commit();
            return ['id' => $bookingId, 'from_status' => $from, 'status' => $to];
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function assignStaff(int $bookingId, int $staffId, int $adminId, ?string $note = null): array
    {
        $this->pdo->beginTransaction();
        try {
            $bookingStmt = $this->pdo->prepare('SELECT id,status,assigned_staff_id FROM bookings WHERE id=? FOR UPDATE');
            $bookingStmt->execute([$bookingId]);
            $booking = $bookingStmt->fetch();
            if (!$booking) { $this->pdo->rollBack(); api_error(404, 'NOT_FOUND', 'Booking not found.'); }
            if (!in_array($booking['status'], ['confirmed','assigned'], true)) {
                $this->pdo->rollBack();
                api_error(409, 'INVALID_TRANSITION', 'Only confirmed or assigned bookings can be assigned.');
            }
            $staffStmt = $this->pdo->prepare("SELECT id FROM staff WHERE id=? AND status='active' FOR UPDATE");
            $staffStmt->execute([$staffId]);
            if (!$staffStmt->fetchColumn()) { $this->pdo->rollBack(); api_error(422, 'VALIDATION_ERROR', 'Choose an active staff member.'); }
            $previous = $booking['assigned_staff_id'] === null ? null : (int)$booking['assigned_staff_id'];
            $update = $this->pdo->prepare("UPDATE bookings SET assigned_staff_id=?,status='assigned' WHERE id=?");
            $update->execute([$staffId, $bookingId]);
            $assignmentStatus = $previous === null ? 'assigned' : 'reassigned';
            $assignment = $this->pdo->prepare('INSERT INTO staff_assignments (booking_id,staff_id,assigned_by,status,note) VALUES (?,?,?,?,?)');
            $assignment->execute([$bookingId, $staffId, $adminId, $assignmentStatus, $note]);
            if ($booking['status'] !== 'assigned') {
                $history = $this->pdo->prepare('INSERT INTO booking_status_history (booking_id,from_status,to_status,changed_by,note) VALUES (?,? ,\'assigned\',?,?)');
                $history->execute([$bookingId, $booking['status'], $adminId, $note]);
                $event=$this->pdo->prepare("INSERT INTO tracking_events (booking_id,event_type,status,note,created_by) VALUES (?,'status_changed','assigned',?,?)");$event->execute([$bookingId,$note,$adminId]);
            }
            $this->pdo->commit();
            return ['booking_id' => $bookingId, 'staff_id' => $staffId, 'status' => 'assigned', 'reassigned' => $previous !== null];
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function trackById(int $id, string $trackingCode): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id,booking_ref,status,tracking_code,created_at,updated_at FROM bookings WHERE id=? AND tracking_code=? LIMIT 1');
        $stmt->execute([$id, $trackingCode]);
        return $this->withHistory($stmt->fetch());
    }

    public function trackByCode(string $trackingCode): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id,booking_ref,status,tracking_code,created_at,updated_at FROM bookings WHERE tracking_code=? LIMIT 1');
        $stmt->execute([$trackingCode]);
        return $this->withHistory($stmt->fetch());
    }

    public function list(array $filters): array
    {
        $page = filter_var($filters['page'] ?? 1, FILTER_VALIDATE_INT);
        $limit = filter_var($filters['limit'] ?? 20, FILTER_VALIDATE_INT);
        if ($page === false || $page < 1 || $limit === false || $limit < 1 || $limit > 100) api_error(422, 'VALIDATION_ERROR', 'Invalid pagination values.');
        $where = [];
        $params = [];
        if (isset($filters['status']) && $filters['status'] !== '') {
            if (!array_key_exists((string)$filters['status'], self::TRANSITIONS)) api_error(422, 'VALIDATION_ERROR', 'Unknown booking status.');
            $where[] = 'b.status=?'; $params[] = $filters['status'];
        }
        if (isset($filters['search']) && trim((string)$filters['search']) !== '') {
            $term = '%' . trim((string)$filters['search']) . '%';
            $where[] = '(b.booking_ref LIKE ? OR b.customer_name LIKE ? OR b.customer_phone LIKE ?)';
            array_push($params, $term, $term, $term);
        }
        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM bookings b' . $whereSql);
        $count->execute($params);
        $total = (int)$count->fetchColumn();
        $stmt = $this->pdo->prepare('SELECT b.id,b.booking_ref,b.customer_name,b.customer_phone,b.status,b.total,b.tracking_code,b.assigned_staff_id,s.name AS staff_name,b.created_at FROM bookings b LEFT JOIN staff s ON s.id=b.assigned_staff_id' . $whereSql . ' ORDER BY b.id DESC LIMIT ? OFFSET ?');
        foreach ($params as $i => $value) $stmt->bindValue($i + 1, $value, PDO::PARAM_STR);
        $stmt->bindValue(count($params) + 1, (int)$limit, PDO::PARAM_INT);
        $stmt->bindValue(count($params) + 2, ((int)$page - 1) * (int)$limit, PDO::PARAM_INT);
        $stmt->execute();
        return ['items' => $stmt->fetchAll(), 'pagination' => ['page' => (int)$page, 'limit' => (int)$limit, 'total' => $total, 'pages' => (int)ceil($total / max(1,(int)$limit))]];
    }

    public function history(int $bookingId): array
    {
        $stmt = $this->pdo->prepare('SELECT h.id,h.from_status,h.to_status,h.note,h.created_at,u.username AS changed_by FROM booking_status_history h LEFT JOIN users u ON u.id=h.changed_by WHERE h.booking_id=? ORDER BY h.id ASC');
        $stmt->execute([$bookingId]);
        return $stmt->fetchAll();
    }

    public function recordTrackingEvent(int $bookingId,int $userId,string $eventType,?string $location,?string $note,?string $proofPath): array
    {
        if(!in_array($eventType,['location_update','pickup','delivery','proof'],true))api_error(422,'VALIDATION_ERROR','Unsupported tracking event type.');
        if($location!==null&&mb_strlen($location)>255)api_error(422,'VALIDATION_ERROR','Location must be at most 255 characters.');
        if($note!==null&&mb_strlen($note)>500)api_error(422,'VALIDATION_ERROR','Note must be at most 500 characters.');
        if($proofPath!==null){$proofPath=(string)(parse_url($proofPath,PHP_URL_PATH)?:$proofPath);if(!preg_match('~^/roomshift/assets/uploads/[a-f0-9]{40}\.(jpg|png|webp)$~',$proofPath))api_error(422,'VALIDATION_ERROR','Proof must reference a validated uploaded image.');}
        $exists=$this->pdo->prepare('SELECT id FROM bookings WHERE id=?');$exists->execute([$bookingId]);if(!$exists->fetchColumn())api_error(404,'NOT_FOUND','Booking not found.');
        $stmt=$this->pdo->prepare('INSERT INTO tracking_events (booking_id,event_type,location_label,note,proof_path,created_by) VALUES (?,?,?,?,?,?)');
        $stmt->execute([$bookingId,$eventType,$location,$note,$proofPath,$userId]);
        return ['id'=>(int)$this->pdo->lastInsertId(),'booking_id'=>$bookingId,'event_type'=>$eventType];
    }

    private function withHistory(array|false $booking): ?array
    {
        if ($booking === false) return null;
        $stmt = $this->pdo->prepare('SELECT from_status,to_status,note,created_at FROM booking_status_history WHERE booking_id=? ORDER BY id ASC');
        $stmt->execute([(int)$booking['id']]);
        $booking['history'] = $stmt->fetchAll();
        $events=$this->pdo->prepare('SELECT event_type,status,location_label,note,proof_path,created_at FROM tracking_events WHERE booking_id=? ORDER BY id ASC');
        $events->execute([(int)$booking['id']]);
        $booking['tracking_events']=$events->fetchAll();
        return $booking;
    }

    private function queueCustomerNotification(int $bookingId, string $email, string $phone, string $template, array $payload): void
    {
        $recipient=trim($email)!==''?$email:$phone;$channel=trim($email)!==''?'email':'internal';
        $stmt=$this->pdo->prepare("INSERT INTO notifications (booking_id,recipient,channel,template_key,payload,status) VALUES (?,?,?,?,?,'queued')");
        $stmt->execute([$bookingId,$recipient,$channel,$template,json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)]);
    }

    private function created(array $row, bool $duplicate): array
    {
        return ['id' => (int)$row['id'], 'booking_ref' => $row['booking_ref'], 'tracking_code' => $row['tracking_code'], 'status' => $row['status'], 'duplicate' => $duplicate];
    }
}
