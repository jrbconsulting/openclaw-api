# JRB Remote Site API - Complete Endpoint Reference

**Version:** 6.5.0  
**Last Updated:** March 6, 2026  
**Base URL:** `{site_url}/wp-json/jrb/v1/`  
**Authentication Header:** `X-JRBRemoteSite-Token: <your-token>`

---

## Table of Contents

1. [Authentication](#authentication)
2. [System & Core](#system--core)
3. [Posts & Pages](#posts--pages)
4. [Media Library](#media-library)
5. [FluentCRM](#fluentcrm)
6. [FluentSupport](#fluentsupport)
7. [FluentForms](#fluentforms)
8. [FluentProject](#fluentproject)
9. [FluentCommunity](#fluentcommunity)
10. [PublishPress](#publishpress)
11. [Plugin Management](#plugin-management)
12. [Response Format](#response-format)
13. [Error Codes](#error-codes)

---

## Authentication

All API requests require authentication via the `X-JRBRemoteSite-Token` header:

```bash
curl -H "X-JRBRemoteSite-Token: your-api-token" \
     https://yoursite.com/wp-json/jrb/v1/site-info
```

**Token Configuration:**
- Generate tokens in WordPress Admin → Settings → JRB Remote API
- Tokens are hashed using `password_hash()` (bcrypt) for secure storage
- Each token can have a custom set of granular capabilities
- Tokens can be rotated/regenerated at any time

**Backward Compatibility:**
The legacy `X-OpenClaw-Token` header is still supported for v6.5.0 but will be removed in v7.0.0.

---

## System & Core

### Get Site Information

**Endpoint:** `GET /site-info`  
**Capability:** `site_info`  
**Description:** Get basic WordPress site information

**Example:**
```bash
curl -H "X-JRBRemoteSite-Token: your-token" \
     https://yoursite.com/wp-json/jrb/v1/site-info
```

**Response:**
```json
{
  "success": true,
  "data": {
    "url": "https://yoursite.com",
    "title": "My WordPress Site",
    "description": "Just another WordPress site",
    "version": "6.4.0",
    "language": "en_US",
    "timezone": "Australia/Sydney"
  }
}
```

---

### Get Diagnostics - Health

**Endpoint:** `GET /diagnostics/health`  
**Capability:** `diagnostics_health`  
**Description:** Get WordPress Site Health information

**Example:**
```bash
curl -H "X-JRBRemoteSite-Token: your-token" \
     https://yoursite.com/wp-json/jrb/v1/diagnostics/health
```

**Response:**
```json
{
  "success": true,
  "data": {
    "status": "operational",
    "wp_version": "6.4.0",
    "php_version": "8.2.0",
    "debug_mode": false,
    "active_theme": "twentytwentyfour",
    "health_engine": "WP_Site_Health"
  }
}
```

---

### Get Diagnostics - Server

**Endpoint:** `GET /diagnostics/server`  
**Capability:** `diagnostics_server`  
**Description:** Get server environment information

**Example:**
```bash
curl -H "X-JRBRemoteSite-Token: your-token" \
     https://yoursite.com/wp-json/jrb/v1/diagnostics/server
```

**Response:**
```json
{
  "success": true,
  "data": {
    "os": "Linux",
    "server_software": "Apache/2.4.57",
    "memory_limit": "256M",
    "post_max_size": "64M",
    "upload_max_filesize": "64M",
    "max_execution_time": "300",
    "disk_free_space": "45.2 GB"
  }
}
```

---

### Get Active Plugins

**Endpoint:** `GET /plugins`  
**Capability:** `plugins_inspect`  
**Description:** List active plugins

**Example:**
```bash
curl -H "X-JRBRemoteSite-Token: your-token" \
     https://yoursite.com/wp-json/jrb/v1/plugins
```

**Response:**
```json
{
  "success": true,
  "data": [
    {
      "name": "FluentCRM",
      "version": "2.6.49",
      "author": "Fluent Labs",
      "active": true
    },
    {
      "name": "Fluent Support",
      "version": "1.1.20",
      "author": "Fluent Labs",
      "active": true
    }
  ]
}
```

---

## Posts & Pages

### List Posts

**Endpoint:** `GET /posts`  
**Capability:** `posts_read`  
**Parameters:**
- `page` (integer, default: 1)
- `per_page` (integer, default: 20, max: 100)
- `status` (string: publish, draft, pending, private)

**Example:**
```bash
curl -H "X-JRBRemoteSite-Token: your-token" \
     "https://yoursite.com/wp-json/jrb/v1/posts?status=publish&per_page=10"
```

**Response:**
```json
{
  "success": true,
  "data": [
    {
      "id": 123,
      "title": "My Post Title",
      "content": "Post content...",
      "status": "publish",
      "author": 1,
      "date": "2026-03-06T10:00:00",
      "modified": "2026-03-06T10:00:00"
    }
  ],
  "meta": {
    "total": 50,
    "page": 1,
    "per_page": 10,
    "pages": 5
  }
}
```

---

### Create Post

**Endpoint:** `POST /posts`  
**Capability:** `posts_create`  
**Body:**
```json
{
  "title": "New Post",
  "content": "Post content here",
  "status": "draft"
}
```

**Example:**
```bash
curl -X POST \
  -H "X-JRBRemoteSite-Token: your-token" \
  -H "Content-Type: application/json" \
  -d '{"title":"New Post","content":"Content","status":"draft"}' \
  https://yoursite.com/wp-json/jrb/v1/posts
```

**Response:**
```json
{
  "success": true,
  "data": {
    "id": 124,
    "title": "New Post",
    "status": "draft"
  }
}
```

---

### Update Post

**Endpoint:** `PUT /posts/{id}`  
**Capability:** `posts_update`

**Example:**
```bash
curl -X PUT \
  -H "X-JRBRemoteSite-Token: your-token" \
  -H "Content-Type: application/json" \
  -d '{"title":"Updated Title","status":"publish"}' \
  https://yoursite.com/wp-json/jrb/v1/posts/123
```

---

### Delete Post

**Endpoint:** `DELETE /posts/{id}`  
**Capability:** `posts_delete`  
**Parameters:**
- `force` (boolean, default: false) - Skip trash

**Example:**
```bash
curl -X DELETE \
  -H "X-JRBRemoteSite-Token: your-token" \
  "https://yoursite.com/wp-json/jrb/v1/posts/123?force=true"
```

---

## Media Library

### List Media

**Endpoint:** `GET /media`  
**Capability:** `media_read`  
**Parameters:**
- `page` (integer, default: 1)
- `per_page` (integer, default: 20, max: 100)
- `mime_type` (string: image, image/jpeg, application/pdf, etc.)
- `search` (string)
- `parent` (integer)
- `author` (integer)

**Example:**
```bash
curl -H "X-JRBRemoteSite-Token: your-token" \
     "https://yoursite.com/wp-json/jrb/v1/media?mime_type=image&per_page=10"
```

**Response:**
```json
{
  "success": true,
  "data": [
    {
      "id": 456,
      "title": "My Image",
      "alt_text": "Alt text",
      "mime_type": "image/jpeg",
      "source_url": "https://yoursite.com/wp-content/uploads/2026/03/image.jpg",
      "sizes": {
        "thumbnail": "https://yoursite.com/wp-content/uploads/2026/03/image-150x150.jpg",
        "medium": "https://yoursite.com/wp-content/uploads/2026/03/image-300x300.jpg",
        "large": "https://yoursite.com/wp-content/uploads/2026/03/image-1024x1024.jpg"
      }
    }
  ]
}
```

---

### Upload Media

**Endpoint:** `POST /media`  
**Capability:** `media_upload`  
**Content-Type:** `multipart/form-data`  
**CSRF Protection:** Required for form uploads (see below)

**Example:**
```bash
curl -X POST \
  -H "X-JRBRemoteSite-Token: your-token" \
  -H "X-CSRF-Token: <csrf-token>" \
  -F "file=@/path/to/image.jpg" \
  -F "title=My Image" \
  -F "alt=Alt text" \
  https://yoursite.com/wp-json/jrb/v1/media
```

**Generating CSRF Token:**
```php
// In WordPress
$csrf_token = wp_create_nonce('openclaw_media_upload');
```

**Response:**
```json
{
  "success": true,
  "data": {
    "id": 789,
    "title": "My Image",
    "source_url": "https://yoursite.com/wp-content/uploads/2026/03/image.jpg",
    "mime_type": "image/jpeg"
  }
}
```

---

### Update Media

**Endpoint:** `PUT /media/{id}`  
**Capability:** `media_edit`  
**Body:**
```json
{
  "title": "Updated Title",
  "alt_text": "Updated alt text",
  "caption": "Image caption"
}
```

**Example:**
```bash
curl -X PUT \
  -H "X-JRBRemoteSite-Token: your-token" \
  -H "Content-Type: application/json" \
  -d '{"title":"Updated","alt_text":"New alt"}' \
  https://yoursite.com/wp-json/jrb/v1/media/789
```

---

### Delete Media

**Endpoint:** `DELETE /media/{id}`  
**Capability:** `media_delete`  
**Parameters:**
- `force` (boolean, default: false)

**Example:**
```bash
curl -X DELETE \
  -H "X-JRBRemoteSite-Token: your-token" \
  "https://yoursite.com/wp-json/jrb/v1/media/789?force=true"
```

---

## FluentCRM

**Module Dependency:** FluentCRM plugin must be active

### List Subscribers

**Endpoint:** `GET /crm/subscribers`  
**Capability:** `crm_subscribers_read`  
**Parameters:**
- `page` (integer, default: 1)
- `per_page` (integer, default: 20, max: 100)
- `list_id` (integer)
- `tag_id` (integer)
- `search` (string)
- `status` (string: subscribed, unsubscribed, pending, bounced)

**Example:**
```bash
curl -H "X-JRBRemoteSite-Token: your-token" \
     "https://yoursite.com/wp-json/jrb/v1/crm/subscribers?list_id=1&status=subscribed"
```

**Response:**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "email": "john@example.com",
      "first_name": "John",
      "last_name": "Doe",
      "status": "subscribed",
      "created_at": "2026-03-01T10:00:00"
    }
  ],
  "meta": {
    "total": 150,
    "page": 1,
    "per_page": 20,
    "pages": 8
  }
}
```

---

### Create Subscriber

**Endpoint:** `POST /crm/subscribers`  
**Capability:** `crm_subscribers_create`  
**Body:**
```json
{
  "email": "new@example.com",
  "first_name": "Jane",
  "last_name": "Smith",
  "status": "subscribed",
  "lists": [1, 2],
  "tags": [5]
}
```

**Example:**
```bash
curl -X POST \
  -H "X-JRBRemoteSite-Token: your-token" \
  -H "Content-Type: application/json" \
  -d '{"email":"jane@example.com","first_name":"Jane","last_name":"Smith","lists":[1,2],"tags":[5]}' \
  https://yoursite.com/wp-json/jrb/v1/crm/subscribers
```

**Response:**
```json
{
  "success": true,
  "data": {
    "id": 151,
    "email": "jane@example.com",
    "first_name": "Jane",
    "last_name": "Smith"
  }
}
```

---

### Get Subscriber

**Endpoint:** `GET /crm/subscribers/{id}`  
**Capability:** `crm_subscribers_read`

**Example:**
```bash
curl -H "X-JRBRemoteSite-Token: your-token" \
     https://yoursite.com/wp-json/jrb/v1/crm/subscribers/151
```

---

### Update Subscriber

**Endpoint:** `PUT /crm/subscribers/{id}`  
**Capability:** `crm_subscribers_update`  
**Body:**
```json
{
  "first_name": "Updated",
  "last_name": "Name",
  "status": "subscribed"
}
```

---

### Delete Subscriber

**Endpoint:** `DELETE /crm/subscribers/{id}`  
**Capability:** `crm_subscribers_delete`

---

### Get Lists

**Endpoint:** `GET /crm/lists`  
**Capability:** `crm_lists_read`

**Response:**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "title": "Newsletter",
      "description": "Monthly newsletter subscribers",
      "subscriber_count": 150
    }
  ]
}
```

---

### Get Tags

**Endpoint:** `GET /crm/tags`  
**Capability:** `crm_tags_read`

---

### Add to List

**Endpoint:** `POST /crm/subscribers/{id}/add-list`  
**Capability:** `crm_lists_manage`  
**Body:**
```json
{
  "list_id": 1
}
```

---

### Add Tag

**Endpoint:** `POST /crm/subscribers/{id}/add-tag`  
**Capability:** `crm_tags_manage`  
**Body:**
```json
{
  "tag_id": 5
}
```

---

### Get Campaigns

**Endpoint:** `GET /crm/campaigns`  
**Capability:** `crm_campaigns_read`

---

### Send Campaign

**Endpoint:** `POST /crm/campaigns/{id}/send`  
**Capability:** `crm_campaigns_send`

---

### Get Stats

**Endpoint:** `GET /crm/stats`  
**Capability:** `crm_reports_read`

---

## FluentSupport

**Module Dependency:** Fluent Support plugin must be active

### List Tickets

**Endpoint:** `GET /support/tickets`  
**Capability:** `support_tickets_read`  
**Parameters:**
- `page` (integer, default: 1)
- `per_page` (integer, default: 20)
- `status` (string: new, open, closed, spam)
- `priority` (string: low, medium, high, urgent)

**Example:**
```bash
curl -H "X-JRBRemoteSite-Token: your-token" \
     "https://yoursite.com/wp-json/jrb/v1/support/tickets?status=open"
```

**Response:**
```json
{
  "success": true,
  "data": [
    {
      "id": 100,
      "title": "API Question",
      "content": "How do I use the API?",
      "customer_id": 50,
      "status": "open",
      "priority": "medium",
      "agent_id": 1,
      "created_at": "2026-03-05T14:00:00"
    }
  ]
}
```

---

### Create Ticket

**Endpoint:** `POST /support/tickets`  
**Capability:** `support_tickets_create`  
**Body:**
```json
{
  "title": "New Support Ticket",
  "content": "I need help with...",
  "customer_id": 50,
  "priority": "medium"
}
```

---

### Get Ticket

**Endpoint:** `GET /support/tickets/{id}`  
**Capability:** `support_tickets_read`

---

### Update Ticket

**Endpoint:** `PUT /support/tickets/{id}`  
**Capability:** `support_tickets_update`  
**Body:**
```json
{
  "status": "closed",
  "priority": "high"
}
```

---

### Add Response

**Endpoint:** `POST /support/respond`  
**Capability:** `support_responses_create`  
**Body:**
```json
{
  "ticket_id": 100,
  "content": "Thanks for your question...",
  "is_note": false
}
```

**Note:** Setting `is_note: true` creates an internal note (customer doesn't see it)

---

### Get Customers

**Endpoint:** `GET /support/customers`  
**Capability:** `support_customers_read`

---

### Assign Ticket

**Endpoint:** `POST /support/assign`  
**Capability:** `support_tickets_assign`  
**Body:**
```json
{
  "ticket_id": 100,
  "agent_id": 5
}
```

---

### Sync Forms to Tickets

**Endpoint:** `POST /support/sync-forms`  
**Capability:** `support_sync`  
**Description:** Sync FluentForms submissions to FluentSupport tickets

---

## FluentForms

**Module Dependency:** Fluent Forms plugin must be active

### List Forms

**Endpoint:** `GET /forms`  
**Capability:** `forms_read`

**Response:**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "title": "Contact Form",
      "status": "published",
      "type": "form",
      "entries_count": 45,
      "created_at": "2026-01-01T10:00:00"
    }
  ]
}
```

---

### Get Form

**Endpoint:** `GET /forms/{id}`  
**Capability:** `forms_read`

---

### List Entries

**Endpoint:** `GET /forms/{id}/entries`  
**Capability:** `forms_entries_read`  
**Parameters:**
- `page` (integer, default: 1)
- `per_page` (integer, default: 20)

---

### Submit Form

**Endpoint:** `POST /forms/{id}/entries`  
**Capability:** `forms_submit`  
**Body:**
```json
{
  "input_1": "John",
  "input_2": "Doe",
  "input_3": "john@example.com"
}
```

**Note:** Field keys depend on form schema (use `GET /forms/{id}` to get schema)

---

### Export Entries

**Endpoint:** `GET /forms/{id}/export`  
**Capability:** `forms_entries_export`  
**Parameters:**
- `format` (string: csv, json, default: json)

---

### Delete Entry

**Endpoint:** `DELETE /entries/{entry_id}`  
**Capability:** `forms_entries_delete`

---

## FluentProject

**Module Dependency:** Fluent Boards (FluentProject) plugin must be active

### List Projects

**Endpoint:** `GET /project/projects`  
**Capability:** `project_tasks_read`  
**Parameters:**
- `status` (string: active, completed, archived)

---

### Create Project

**Endpoint:** `POST /project/projects`  
**Capability:** `project_tasks_create`  
**Body:**
```json
{
  "title": "New Project",
  "description": "Project description",
  "status": "active",
  "priority": "normal"
}
```

---

### List Tasks

**Endpoint:** `GET /project/tasks`  
**Capability:** `project_tasks_read`  
**Parameters:**
- `board_id` (integer)
- `status` (string: todo, in_progress, done)

---

### Create Task

**Endpoint:** `POST /project/tasks`  
**Capability:** `project_tasks_create`  
**Body:**
```json
{
  "board_id": 1,
  "title": "New Task",
  "description": "Task description",
  "assignee_id": 5
}
```

---

### Update Task

**Endpoint:** `PUT /project/tasks/{id}`  
**Capability:** `project_tasks_update`  
**Body:**
```json
{
  "status": "in_progress",
  "assignee_id": 3
}
```

---

### Delete Task

**Endpoint:** `DELETE /project/tasks/{id}`  
**Capability:** `project_tasks_delete`

---

### Get Boards

**Endpoint:** `GET /project/boards`  
**Capability:** `project_boards_read`

---

### Add Comment

**Endpoint:** `POST /project/comments`  
**Capability:** `project_comments_create`  
**Body:**
```json
{
  "task_id": 50,
  "content": "Comment content"
}
```

---

## FluentCommunity

**Module Dependency:** Fluent Community plugin must be active

### List Posts

**Endpoint:** `GET /community/posts`  
**Capability:** `community_posts_read`  
**Parameters:**
- `group_id` (integer)
- `page` (integer, default: 1)
- `per_page` (integer, default: 20)

---

### Create Post

**Endpoint:** `POST /community/posts`  
**Capability:** `community_posts_create`  
**Body:**
```json
{
  "group_id": 1,
  "title": "New Post",
  "content": "Post content"
}
```

---

### Get Groups

**Endpoint:** `GET /community/groups`  
**Capability:** `community_groups_read`

---

### List Members

**Endpoint:** `GET /community/members`  
**Capability:** `community_members_read`  
**Parameters:**
- `page` (integer, default: 1)
- `per_page` (integer, default: 20)

---

## PublishPress

**Module Dependency:** PublishPress Statuses plugin must be active

### Get Custom Statuses

**Endpoint:** `GET /statuses`  
**Capability:** `statuses_read`

**Response:**
```json
{
  "success": true,
  "data": [
    {
      "name": "pitch",
      "label": "Pitch"
    },
    {
      "name": "in-progress",
      "label": "In Progress"
    },
    {
      "name": "pending-review",
      "label": "Pending Review"
    },
    {
      "name": "approved",
      "label": "Approved"
    }
  ]
}
```

---

## Plugin Management

### Self-Update

**Endpoint:** `POST /self-update`  
**Capability:** `plugins_update`  
**Description:** Check and install plugin updates from GitHub

**Example:**
```bash
curl -X POST \
  -H "X-JRBRemoteSite-Token: your-token" \
  https://yoursite.com/wp-json/jrb/v1/self-update
```

**Response:**
```json
{
  "status": "updated",
  "message": "JRB Remote API updated successfully",
  "version": "6.5.0"
}
```

---

### Update from URL

**Endpoint:** `POST /self-update-from-url`  
**Capability:** `plugins_update`  
**Body:**
```json
{
  "url": "https://github.com/JRBConsulting/jrb-remote-site-api-openclaw/releases/download/v6.5.0/jrb-remote-site-api-openclaw.zip"
}
```

**Trusted Hosts:**
- github.com
- objects.githubusercontent.com
- raw.githubusercontent.com
- openclaw.ai
- clawhub.ai

---

## Response Format

### Success Response

```json
{
  "success": true,
  "data": { ... },
  "status_code": 200,
  "headers": { ... }
}
```

### Error Response

```json
{
  "success": false,
  "error": {
    "code": "invalid_token",
    "message": "Invalid API token",
    "status": 401
  },
  "data": null
}
```

---

## Error Codes

| Code | Status | Meaning | Resolution |
|------|--------|---------|------------|
| `invalid_token` | 401 | API token is missing or incorrect | Verify token in request header |
| `forbidden` | 403 | Capability not enabled for this token | Check capability grants in WordPress Admin |
| `not_found` | 404 | Resource doesn't exist | Verify resource ID is correct |
| `validation_error` | 400 | Invalid request data | Check request payload format |
| `module_not_active` | 404 | Plugin module not installed/active | Install and activate required plugin |
| `csrf_invalid` | 403 | Invalid CSRF token (uploads) | Generate new CSRF token |
| `file_too_large` | 413 | Upload exceeds max size | Check file size (default: 10MB) |
| `rate_limited` | 429 | Too many requests | Implement exponential backoff |

---

## Pagination

All list endpoints support pagination:

**Request Parameters:**
- `page` (integer, default: 1) - Page number
- `per_page` (integer, default: 20, max: 100) - Items per page

**Response Metadata:**
```json
{
  "success": true,
  "data": [...],
  "meta": {
    "total": 150,
    "page": 1,
    "per_page": 20,
    "pages": 8
  }
}
```

---

## Rate Limiting

The API does not enforce strict rate limiting by default, but clients should:

1. Implement exponential backoff on 429 responses
2. Use pagination for large datasets
3. Cache responses when appropriate
4. Batch operations when possible

**Recommended Limits:**
- 100 requests/minute for read operations
- 20 requests/minute for write operations
- 5 requests/minute for delete operations

---

**For more information:**
- [Permissions Reference](./PERMISSIONS.md)
- [Module Architecture](./MODULES.md)
- [Installation Guide](./INSTALLATION.md)
