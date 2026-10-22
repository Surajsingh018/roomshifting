#!/usr/bin/env python3
"""HTTP integration checks; run only against a disposable restored test DB."""
import http.cookiejar
import json
import os
import re
import subprocess
import urllib.error
import urllib.parse
import urllib.request
import uuid

base = os.environ.get('ROOMSHIFT_TEST_URL', 'http://127.0.0.1:8765/roomshift')
fixture_path = os.path.join(os.environ['TEMP'], 'roomshift_fixture_audit.json')
with open(fixture_path, encoding='utf-8') as handle:
    fixture = json.load(handle)
users = fixture['users']
results = []


def request(path, method='GET', body=None, token=None, opener=None):
    data = None if body is None else json.dumps(body).encode()
    headers = {'Accept': 'application/json'}
    if data is not None:
        headers['Content-Type'] = 'application/json'
    if token:
        headers['Authorization'] = 'Bearer ' + token
    req = urllib.request.Request(base + path, data=data, headers=headers, method=method)
    client = opener or urllib.request.build_opener(urllib.request.HTTPHandler())
    try:
        response = client.open(req, timeout=10)
    except urllib.error.HTTPError as error:
        response = error
    raw = response.read()
    try:
        payload = json.loads(raw)
    except (ValueError, UnicodeDecodeError):
        payload = None
    return response.code, response.headers, payload, raw.decode(errors='replace')


def check(condition, label):
    results.append((label, bool(condition)))
    print(('PASS' if condition else 'FAIL') + ': ' + label)


def envelope(response):
    return isinstance(response[2], dict) and all(
        key in response[2] for key in ('success', 'data', 'message', 'error_code')
    )


# Public catalog, output contract, and parameterized search.
services = request('/api/services')
check(services[0] == 200 and envelope(services) and services[2]['success']
      and len(services[2]['data']['items']) >= 3, 'DB catalog uses the shared API envelope')
search = request('/api/services?search=%27%20OR%201%3D1--')
check(search[0] == 200 and envelope(search), 'SQL injection search payload is handled as data')
homepage = request('/')
check(homepage[0] == 200 and '&lt;script&gt;alert(1)&lt;/script&gt;' in homepage[3]
      and '<script>alert(1)</script>' not in homepage[3], 'stored service text is escaped in website HTML')

# API RBAC.
anonymous = request('/api/bookings')
check(anonymous[0] == 401 and envelope(anonymous), 'anonymous caller cannot list bookings')
partner = request('/api/bookings', token=users['partner']['token'])
check(partner[0] == 403 and envelope(partner), 'non-staff role cannot list bookings')
staff_list = request('/api/bookings', token=users['staff']['token'])
check(staff_list[0] == 200 and envelope(staff_list), 'staff can use permitted booking list')

# Enquiry idempotency and strict server-side input.
key = 'audit-' + uuid.uuid4().hex
payload = {
    'idempotency_key': key, 'name': 'Audit Synthetic', 'phone': '5550101',
    'email': 'audit@example.invalid', 'service_id': fixture['service_id'],
    'from_address': 'Synthetic origin', 'to_address': 'Synthetic destination',
    'floors': 2, 'distance_km': 10, 'packing_required': True, 'inventory': [],
}
created = request('/api/enquiries', 'POST', payload)
check(created[0] == 201 and envelope(created) and created[2]['success'], 'enquiry is persisted')
enquiry = created[2]['data']
again = request('/api/enquiries', 'POST', payload)
check(again[0] == 200 and again[2]['data']['id'] == enquiry['id']
      and again[2]['data']['duplicate'], 'same enquiry retry is idempotent')
conflict = request('/api/enquiries', 'POST', {**payload, 'name': 'Changed Payload'})
check(conflict[0] == 409 and envelope(conflict)
      and conflict[2]['error_code'] == 'IDEMPOTENCY_CONFLICT', 'changed payload cannot reuse enquiry key')
client_price = request('/api/enquiries', 'POST', {**payload, 'idempotency_key': 'price-' + uuid.uuid4().hex, 'total': 1})
check(client_price[0] == 422 and client_price[2]['error_code'] == 'CLIENT_PRICING_FORBIDDEN', 'client supplied total is rejected')
client_discount = request('/api/enquiries', 'POST', {**payload, 'idempotency_key': 'discount-' + uuid.uuid4().hex, 'discount_code': 'EXPIRED'})
check(client_discount[0] == 422 and client_discount[2]['error_code'] == 'VALIDATION_ERROR', 'client-supplied discount is rejected without approved discount rules')

# Quote IDOR and authoritative pricing.
no_capability = request('/api/quotes/calculate', 'POST', {'enquiry_id': enquiry['id']})
check(no_capability[0] == 422 and envelope(no_capability), 'quote calculation requires enquiry capability')
calculated = request('/api/quotes/calculate', 'POST', {
    'enquiry_id': enquiry['id'], 'enquiry_token': enquiry['enquiry_token'],
})
check(calculated[0] == 200 and envelope(calculated)
      and float(calculated[2]['data']['breakdown']['total']) > 0, 'quote uses server pricing engine')
quote = calculated[2]['data']
private_quote = request('/api/quotes/' + str(quote['quote_id']))
check(private_quote[0] == 401 and envelope(private_quote), 'quote ID alone does not disclose quote')
authorized_quote = request('/api/quotes/' + str(quote['quote_id']) + '?token=' + quote['quote_token'])
check(authorized_quote[0] == 200 and envelope(authorized_quote), 'signed capability reads quote')

# A key cannot be reused to book a different quote.
second_enquiry = request('/api/enquiries', 'POST', {**payload, 'idempotency_key': 'other-' + uuid.uuid4().hex})
second_quote = request('/api/quotes/calculate', 'POST', {
    'enquiry_id': second_enquiry[2]['data']['id'],
    'enquiry_token': second_enquiry[2]['data']['enquiry_token'],
})
second_quote_data = second_quote[2]['data']

# Booking ownership, idempotency, and state machine.
denied_booking = request('/api/bookings', 'POST', {
    'quote_id': quote['quote_id'], 'quote_token': '0' * 64,
    'idempotency_key': 'audit-booking-' + uuid.uuid4().hex,
})
check(denied_booking[0] == 404 and envelope(denied_booking), 'guessed quote ID cannot create booking')
booking_key = 'audit-booking-' + uuid.uuid4().hex
booking = request('/api/bookings', 'POST', {
    'quote_id': quote['quote_id'], 'quote_token': quote['quote_token'],
    'idempotency_key': booking_key,
})
check(booking[0] == 201 and envelope(booking), 'booking created from authorized quote')
booking_again = request('/api/bookings', 'POST', {
    'quote_id': quote['quote_id'], 'quote_token': quote['quote_token'],
    'idempotency_key': booking_key,
})
check(booking_again[0] == 200 and booking_again[2]['data']['id'] == booking[2]['data']['id'],
      'booking retry returns original booking')
key_conflict = request('/api/bookings', 'POST', {
    'quote_id': second_quote_data['quote_id'], 'quote_token': second_quote_data['quote_token'],
    'idempotency_key': booking_key,
})
check(key_conflict[0] == 409 and key_conflict[2]['error_code'] == 'IDEMPOTENCY_CONFLICT',
      'booking key cannot be reused with a different quote')
second_booking = request('/api/bookings', 'POST', {
    'quote_id': second_quote_data['quote_id'], 'quote_token': second_quote_data['quote_token'],
    'idempotency_key': 'other-booking-' + uuid.uuid4().hex,
})
payment_key = 'audit-payment-' + uuid.uuid4().hex
payment_body = {
    'booking_id': booking[2]['data']['id'],
    'tracking_code': booking[2]['data']['tracking_code'],
    'idempotency_key': payment_key, 'method': 'cod',
}
payment = request('/api/payments', 'POST', payment_body)
check(payment[0] == 201 and envelope(payment) and payment[2]['data']['status'] == 'pending',
      'payment initialization remains pending without provider success')
payment_retry = request('/api/payments', 'POST', payment_body)
check(payment_retry[0] == 200 and payment_retry[2]['data']['id'] == payment[2]['data']['id'],
      'payment retry returns same payment')
payment_reuse = request('/api/payments', 'POST', {
    'booking_id': second_booking[2]['data']['id'],
    'tracking_code': second_booking[2]['data']['tracking_code'],
    'idempotency_key': payment_key, 'method': 'cod',
})
check(payment_reuse[0] == 409 and envelope(payment_reuse)
      and payment_reuse[2]['error_code'] == 'IDEMPOTENCY_CONFLICT',
      'payment key cannot disclose/reuse payment across bookings')
payment_method_reuse = request('/api/payments', 'POST', {**payment_body, 'method': 'online'})
check(payment_method_reuse[0] == 409 and payment_method_reuse[2]['error_code'] == 'IDEMPOTENCY_CONFLICT',
      'payment key cannot be reused with a different method')
booking_id = booking[2]['data']['id']
staff_change = request(f'/api/bookings/{booking_id}/status', 'PATCH', {'status': 'confirmed'}, users['staff']['token'])
check(staff_change[0] == 403 and envelope(staff_change), 'staff cannot call admin-only status operation')
changed = request(f'/api/bookings/{booking_id}/status', 'PATCH', {'status': 'confirmed'}, users['admin']['token'])
check(changed[0] == 200 and changed[2]['data']['status'] == 'confirmed', 'admin can make legal quote-to-confirmed transition')
illegal = request(f'/api/bookings/{booking_id}/status', 'PATCH', {'status': 'quote'}, users['admin']['token'])
check(illegal[0] == 409 and illegal[2]['error_code'] == 'INVALID_TRANSITION', 'illegal reverse transition is rejected')
tracked = request(f"/api/bookings/{booking_id}/track?code={booking[2]['data']['tracking_code']}")
check(tracked[0] == 200 and envelope(tracked) and len(tracked[2]['data']['history']) >= 2,
      'tracking response contains lifecycle history')
with open(os.path.join(os.environ['TEMP'], 'roomshift_actor_audit.json'), 'w', encoding='utf-8') as handle:
    json.dump({'booking_id': booking_id, 'admin_user_id': users['admin']['id']}, handle)

# Two independent PHP processes race on a fresh quote and the same booking key.
concurrent_key = 'audit-race-' + uuid.uuid4().hex
php = os.environ.get('ROOMSHIFT_PHP', r'C:\xampp\php\php.exe')
worker = os.path.join(os.path.dirname(__file__), 'concurrent_booking_worker.php')
worker_env = os.environ.copy()
worker_env['ROOMSHIFT_DB_NAME'] = os.environ.get('ROOMSHIFT_DB_NAME', '')
worker_env.setdefault('ROOMSHIFT_PUBLIC_TOKEN_KEY', 'audit-public-token-key-32-bytes-long')
worker_env.setdefault('ROOMSHIFT_RATE_LIMIT_KEY', 'audit-rate-limit-key-32-bytes-long')
commands = [[php, worker, str(second_quote_data['quote_id']), concurrent_key, second_quote_data['quote_token']] for _ in range(2)]
processes = [subprocess.Popen(command, stdout=subprocess.PIPE, stderr=subprocess.PIPE, env=worker_env) for command in commands]
worker_results = [process.communicate(timeout=20) + (process.returncode,) for process in processes]
try:
    decoded = [json.loads(result[0]) for result in worker_results if result[2] == 0]
    concurrent_ok = len(decoded) == 2 and decoded[0]['id'] == decoded[1]['id'] and sum(not item['duplicate'] for item in decoded) == 1
except (ValueError, KeyError):
    concurrent_ok = False
check(concurrent_ok, 'two concurrent PHP clients create exactly one booking')

# Authenticated web forms: session-based CSRF and upload MIME validation.
jar = http.cookiejar.CookieJar()
browser = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
login_page = request('/admin/login.php', opener=browser)
token_match = re.search(r'name="_csrf_token" value="([a-f0-9]+)"', login_page[3])
check(login_page[0] == 200 and token_match is not None, 'admin login form renders CSRF token')
csrf = token_match.group(1) if token_match else ''
login_body = urllib.parse.urlencode({
    '_csrf_token': csrf, 'username': users['admin']['username'],
    'password': users['admin']['password'],
}).encode()
login_req = urllib.request.Request(base + '/admin/login.php', data=login_body,
                                   headers={'Content-Type': 'application/x-www-form-urlencoded'})
try:
    logged_in = browser.open(login_req, timeout=10)
except urllib.error.HTTPError as error:
    logged_in = error
check(logged_in.code == 200 and any(cookie.name == 'ROOMSHIFTSESSID' for cookie in jar),
      'database-backed admin login establishes session')
dashboard = request('/admin/dashboard.php', opener=browser)
dashboard_token = re.search(r'name="_csrf_token" value="([a-f0-9]+)"', dashboard[3])
csrf = dashboard_token.group(1) if dashboard_token else ''
check(dashboard[0] == 200 and dashboard_token is not None, 'authenticated admin dashboard renders fresh CSRF token')
missing_csrf = urllib.request.Request(base + '/admin/manage.php', data=b'type=services',
    headers={'Content-Type': 'application/x-www-form-urlencoded'}, method='POST')
try:
    bad_csrf_response = browser.open(missing_csrf, timeout=10)
except urllib.error.HTTPError as error:
    bad_csrf_response = error
check(bad_csrf_response.code == 419, 'authenticated admin mutation rejects missing CSRF token')
# Wrong MIME with correct session CSRF must be rejected before storage.
boundary = 'auditboundary'
multipart = (f'--{boundary}\r\nContent-Disposition: form-data; name="_csrf_token"\r\n\r\n{csrf}\r\n'
             f'--{boundary}\r\nContent-Disposition: form-data; name="media"; filename="x.php"\r\n'
             'Content-Type: application/x-php\r\n\r\n<?php echo 1; ?>\r\n'
             f'--{boundary}--\r\n').encode()
upload_req = urllib.request.Request(base + '/admin/upload.php', data=multipart,
    headers={'Content-Type': f'multipart/form-data; boundary={boundary}'}, method='POST')
try:
    upload = browser.open(upload_req, timeout=10)
except urllib.error.HTTPError as error:
    upload = error
check(upload.code == 415, 'upload rejects PHP/text MIME despite image extension')

# Fixed-window throttling: exhaust the public-enquiry quota last.
rate_statuses = []
for _ in range(11):
    rate_statuses.append(request('/api/enquiries', 'POST', {
        **payload, 'idempotency_key': 'limit-' + uuid.uuid4().hex,
    })[0])
check(rate_statuses[-1] == 429, 'public enquiry rate limit enforces configured threshold')

failed = [label for label, passed in results if not passed]
print(f'HTTP security/workflow checks: {len(results) - len(failed)}/{len(results)} passed')
if failed:
    raise SystemExit('Failed checks: ' + ', '.join(failed))
