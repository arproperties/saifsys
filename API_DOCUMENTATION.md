# HeroSys Mobile Booking API Documentation

Complete API reference for the mobile booking system.

## Base URL

```
http://localhost/herosys/api/mobile
```

Replace `localhost` with your server IP or domain in production.

## Authentication

### Guest Endpoints (No Auth Required)
- `GET /services.php`
- `GET /workers_available.php`
- `POST /bookings.php` (create booking)
- `POST /auth_login.php`
- `POST /auth_verify_otp.php`

### Authenticated Endpoints (JWT Required)
- `GET /bookings.php` (get customer bookings)
- `PUT /bookings.php` (cancel booking)

### JWT Authentication

Include JWT token in the Authorization header:

```
Authorization: Bearer {your-jwt-token}
```

Token expires after 7 days. Refresh by logging in again.

---

## Endpoints

### 1. Services

#### Get All Services

**Endpoint**: `GET /services.php`

**Description**: Retrieve all active services grouped by category.

**Parameters**: None

**Response**:
```json
{
  "success": true,
  "data": {
    "services": [
      {
        "id": 1,
        "name": "Home Cleaning",
        "description": "Professional deep cleaning service for your home",
        "category": "Cleaning",
        "price": "150.00",
        "duration_minutes": 120,
        "image_url": null,
        "is_active": "1"
      },
      {
        "id": 2,
        "name": "AC Maintenance",
        "description": "Complete AC servicing and maintenance",
        "category": "Maintenance",
        "price": "200.00",
        "duration_minutes": 90,
        "image_url": null,
        "is_active": "1"
      }
    ],
    "categories": ["Cleaning", "Maintenance", "Renovation"],
    "total": 8
  }
}
```

**Error Response**:
```json
{
  "success": false,
  "error": "Database error occurred"
}
```

#### Get Single Service

**Endpoint**: `GET /services.php?id={service_id}`

**Parameters**:
- `id` (integer, required): Service ID

**Response**:
```json
{
  "success": true,
  "data": {
    "id": 1,
    "name": "Home Cleaning",
    "description": "Professional deep cleaning service",
    "category": "Cleaning",
    "price": "150.00",
    "duration_minutes": 120,
    "image_url": null,
    "is_active": "1"
  }
}
```

---

### 2. Worker Availability

#### Get Available Time Slots

**Endpoint**: `GET /workers_available.php`

**Description**: Returns available time slots for a service on a specific date, respecting worker shifts, time-off, and existing bookings.

**Parameters**:
- `service_id` (integer, required): Service ID
- `date` (string, required): Date in YYYY-MM-DD format
- `worker_id` (integer, optional): Specific worker ID to check

**Example Request**:
```
GET /workers_available.php?service_id=1&date=2025-11-05
```

**Response**:
```json
{
  "success": true,
  "data": {
    "slots": [
      {
        "datetime": "2025-11-05T09:00:00",
        "worker_id": 5,
        "worker_name": "John Doe"
      },
      {
        "datetime": "2025-11-05T09:30:00",
        "worker_id": 5,
        "worker_name": "John Doe"
      },
      {
        "datetime": "2025-11-05T10:00:00",
        "worker_id": 7,
        "worker_name": "Jane Smith"
      }
    ],
    "date": "2025-11-05",
    "service_id": 1,
    "total_slots": 3
  }
}
```

**Logic**:
1. Finds all bookable workers
2. Checks worker shifts for the day of week
3. Excludes workers on time-off
4. Generates 30-minute time slots within shift hours
5. Excludes slots that overlap with existing bookings
6. Returns available slots sorted by time

**Error Responses**:
```json
{
  "success": false,
  "error": "service_id and date are required"
}
```

```json
{
  "success": false,
  "error": "Invalid date format. Use YYYY-MM-DD"
}
```

---

### 3. Bookings

#### Create Booking (Guest)

**Endpoint**: `POST /bookings.php`

**Description**: Create a new booking. Works for both guest and authenticated users.

**Request Body**:
```json
{
  "service_id": 1,
  "worker_id": 5,
  "scheduled_date": "2025-11-05",
  "scheduled_time": "09:00:00",
  "customer_name": "Jane Smith",
  "customer_phone": "+971501234567",
  "customer_email": "jane@example.com",
  "address": "Villa 123, Palm Jumeirah, Dubai",
  "notes": "Please call before arriving",
  "total_price": 150.00
}
```

**Required Fields**:
- `service_id`
- `scheduled_date`
- `scheduled_time`
- `customer_name`
- `customer_phone`
- `customer_email`
- `address`
- `total_price`

**Optional Fields**:
- `worker_id` (can be assigned later by admin)
- `notes`

**Response**:
```json
{
  "success": true,
  "data": {
    "booking_id": 123,
    "status": "pending"
  },
  "message": "Booking created successfully"
}
```

**Process**:
1. Validates service exists
2. Creates or updates customer record by phone
3. Creates booking with status "pending"
4. Logs booking event
5. Sends confirmation email/SMS

**Error Responses**:
```json
{
  "success": false,
  "error": "Missing required fields: customer_name, customer_phone"
}
```

```json
{
  "success": false,
  "error": "Invalid service"
}
```

#### Get Customer Bookings

**Endpoint**: `GET /bookings.php`

**Authentication**: Required (JWT)

**Description**: Get all bookings for the authenticated customer.

**Headers**:
```
Authorization: Bearer {jwt-token}
```

**Response**:
```json
{
  "success": true,
  "data": [
    {
      "id": 123,
      "service_id": 1,
      "service_name": "Home Cleaning",
      "employee_id": 5,
      "worker_name": "John Doe",
      "scheduled_date": "2025-11-05",
      "scheduled_time": "09:00:00",
      "customer_name": "Jane Smith",
      "customer_phone": "+971501234567",
      "customer_email": "jane@example.com",
      "address": "Villa 123, Palm Jumeirah, Dubai",
      "notes": "Please call before arriving",
      "total_price": "150.00",
      "status": "confirmed",
      "created_at": "2025-11-01T10:30:00",
      "confirmed_at": "2025-11-01T11:00:00"
    }
  ]
}
```

**Statuses**:
- `pending`: Booking created, awaiting confirmation
- `confirmed`: Confirmed by admin
- `assigned`: Worker assigned
- `in_progress`: Service started
- `completed`: Service completed
- `cancelled`: Booking cancelled
- `no_show`: Customer didn't show up

#### Cancel Booking

**Endpoint**: `PUT /bookings.php`

**Authentication**: Required (JWT)

**Description**: Cancel a booking. Must be at least 24 hours before scheduled time.

**Request Body**:
```json
{
  "id": 123,
  "action": "cancel",
  "reason": "Changed plans"
}
```

**Response**:
```json
{
  "success": true,
  "data": {
    "booking_id": 123,
    "status": "cancelled"
  },
  "message": "Booking cancelled successfully"
}
```

**Error Responses**:
```json
{
  "success": false,
  "error": "Bookings must be cancelled at least 24 hours in advance"
}
```

```json
{
  "success": false,
  "error": "This booking cannot be cancelled"
}
```

---

### 4. Authentication

#### Send OTP

**Endpoint**: `POST /auth_login.php`

**Description**: Send OTP code to customer's phone number for login.

**Request Body**:
```json
{
  "phone": "+971501234567"
}
```

**Response**:
```json
{
  "success": true,
  "data": {
    "phone": "+971501234567",
    "otp_sent": true,
    "expires_in_minutes": 10,
    "dev_otp": "123456"
  },
  "message": "OTP sent successfully"
}
```

**Note**: `dev_otp` is only included in development. Remove in production.

**Process**:
1. Generates 6-digit OTP
2. Stores OTP with 10-minute expiry
3. Sends OTP via SMS (or email in development)
4. Creates customer record if doesn't exist

**Error Responses**:
```json
{
  "success": false,
  "error": "Phone number is required"
}
```

#### Verify OTP

**Endpoint**: `POST /auth_verify_otp.php`

**Description**: Verify OTP and receive JWT token for authentication.

**Request Body**:
```json
{
  "phone": "+971501234567",
  "otp": "123456"
}
```

**Response**:
```json
{
  "success": true,
  "data": {
    "token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
    "customer": {
      "id": 1,
      "name": "Jane Smith",
      "phone": "+971501234567",
      "email": "jane@example.com",
      "address": "Villa 123, Palm Jumeirah, Dubai"
    }
  },
  "message": "Login successful"
}
```

**Process**:
1. Validates OTP format
2. Checks OTP matches and hasn't expired
3. Marks customer as verified
4. Generates JWT token (7-day expiry)
5. Returns token and customer data

**Error Responses**:
```json
{
  "success": false,
  "error": "Invalid OTP"
}
```

```json
{
  "success": false,
  "error": "OTP has expired. Please request a new one."
}
```

---

## Error Handling

### HTTP Status Codes

- `200 OK`: Success
- `400 Bad Request`: Invalid input
- `401 Unauthorized`: Authentication required or failed
- `404 Not Found`: Resource not found
- `405 Method Not Allowed`: Wrong HTTP method
- `422 Unprocessable Entity`: Validation failed
- `429 Too Many Requests`: Rate limit exceeded
- `500 Internal Server Error`: Server error

### Error Response Format

All errors follow this format:

```json
{
  "success": false,
  "error": "Error message here"
}
```

---

## Rate Limiting

**Default**: 60 requests per minute per IP address

Exceeding the limit returns:

```json
{
  "success": false,
  "error": "Rate limit exceeded. Please try again later."
}
```

HTTP Status: `429 Too Many Requests`

---

## Security

### CORS

Default configuration allows all origins for development. Configure for production:

```php
header('Access-Control-Allow-Origin: https://yourdomain.com');
```

### Input Validation

- All inputs are sanitized using `htmlspecialchars()` and `strip_tags()`
- SQL injection prevented with prepared statements
- Required fields validated before processing

### JWT Tokens

- Algorithm: HS256
- Expiry: 7 days
- Secret: Configurable via environment variable

### Rate Limiting

- Per-IP tracking
- File-based caching
- Configurable limits

---

## Best Practices

### For Mobile App Developers

1. **Cache Services**: Services rarely change, cache for 1 hour
2. **Retry Logic**: Implement exponential backoff for failed requests
3. **Token Refresh**: Check token expiry and refresh proactively
4. **Error Handling**: Display user-friendly error messages
5. **Loading States**: Show loading indicators during API calls

### For Backend Developers

1. **Monitor Rate Limits**: Adjust based on traffic patterns
2. **Log Errors**: All errors logged to PHP error log
3. **Database Indexing**: Ensure indexes on frequently queried fields
4. **Cleanup**: Periodically clean up expired OTPs and old rate limit files

---

## Changelog

### v1.0 (November 2025)
- Initial release
- Services, availability, and booking endpoints
- OTP authentication
- JWT token support
- Rate limiting
- Audit logging

---

## Support

For API issues:
- Check PHP error logs: `/Applications/XAMPP/xamppfiles/logs/php_error_log`
- Verify database migrations ran successfully
- Test endpoints using curl or Postman
- Review request/response in browser developer tools

---

**Last Updated**: November 1, 2025

