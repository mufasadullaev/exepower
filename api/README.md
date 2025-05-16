# ExePower API Documentation

This is a simple PHP API for the ExePower application.

## Authentication

The API uses JWT (JSON Web Token) for authentication.

### Endpoints

#### Login

```
POST /api/auth/login
```

Request body:
```json
{
  "password": "admin123"
}
```

Response:
```json
{
  "status": "success",
  "message": "Login successful",
  "data": {
    "token": "JWT_TOKEN",
    "user": {
      "id": 1,
      "username": "admin",
      "role": "admin"
    }
  }
}
```

#### Verify Token

```
GET /api/auth/verify
```

Headers:
```
Authorization: Bearer JWT_TOKEN
```

Response:
```json
{
  "status": "success",
  "message": "Token is valid",
  "data": {
    "user": {
      "id": 1,
      "username": "admin",
      "role": "admin"
    }
  }
}
```

## Dashboard

### Get Dashboard Data

```
GET /api/dashboard
```

Headers:
```
Authorization: Bearer JWT_TOKEN
```

Response:
```json
{
  "status": "success",
  "message": "Dashboard data retrieved successfully",
  "data": {
    "stats": {
      "users": 248,
      "sales": 1024,
      "performance": 87,
      "conversion": 24
    },
    "recentActivity": [
      {
        "id": 1,
        "action": "New user registered",
        "user": "John Doe",
        "timestamp": "2023-07-01 12:34:56"
      },
      ...
    ],
    "chartData": {
      "labels": ["January", "February", "March", "April", "May", "June", "July"],
      "datasets": [
        {
          "label": "Sales",
          "data": [65, 59, 80, 81, 56, 55, 40]
        },
        {
          "label": "Revenue",
          "data": [28, 48, 40, 19, 86, 27, 90]
        }
      ]
    }
  }
}
```

## Error Responses

Error responses will have the following format:

```json
{
  "status": "error",
  "message": "Error message"
}
```

Common HTTP status codes:
- 400: Bad Request
- 401: Unauthorized
- 404: Not Found
- 500: Internal Server Error 