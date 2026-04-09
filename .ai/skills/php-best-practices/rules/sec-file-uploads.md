---
title: File Upload Security
impact: CRITICAL
impactDescription: Unrestricted file uploads can lead to remote code execution
tags: security, file-uploads, validation, php8
---

# File Upload Security

Always validate file type, size, and name. Store uploads outside the web root or in non-executable locations.

## Bad Example

```php
<?php

declare(strict_types=1);

// No validation - accepts anything
$file = $_FILES['upload'];
move_uploaded_file($file['tmp_name'], "uploads/{$file['name']}");
// Attacker uploads shell.php → remote code execution

// Only checking extension - easily bypassed
$ext = pathinfo($_FILES['upload']['name'], PATHINFO_EXTENSION);
if ($ext === 'jpg') {
    move_uploaded_file($file['tmp_name'], "uploads/{$file['name']}");
}
// Attacker uploads shell.php.jpg or uses double extension

// Trusting MIME type from client - can be faked
if ($_FILES['upload']['type'] === 'image/jpeg') {
    // Client sends whatever MIME type they want
}

// Storing in web-accessible directory with original name
move_uploaded_file($file['tmp_name'], "public/uploads/{$file['name']}");
```

## Good Example

```php
<?php

declare(strict_types=1);

function handleUpload(array $file): string
{
    // Check for upload errors
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new UploadException('Upload failed with error code: ' . $file['error']);
    }

    // Validate file size (server-side, don't rely on MAX_FILE_SIZE)
    $maxSize = 5 * 1024 * 1024; // 5MB
    if ($file['size'] > $maxSize) {
        throw new UploadException('File exceeds maximum size of 5MB');
    }

    // Validate MIME type using file content (not client-provided type)
    $finfo = new \finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file['tmp_name']);

    $allowedMimes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];

    if (!isset($allowedMimes[$mimeType])) {
        throw new UploadException('File type not allowed: ' . $mimeType);
    }

    // Generate random filename - never use original
    $extension = $allowedMimes[$mimeType];
    $filename = bin2hex(random_bytes(16)) . '.' . $extension;

    // Store outside web root
    $storagePath = '/var/app/storage/uploads';
    $destination = $storagePath . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        throw new UploadException('Failed to store uploaded file');
    }

    return $filename;
}

// For images, verify they are valid images
function validateImage(string $path): void
{
    $imageInfo = getimagesize($path);
    if ($imageInfo === false) {
        unlink($path);
        throw new UploadException('File is not a valid image');
    }
}

// Serve files through a controller (not direct URL access)
function downloadFile(string $filename): void
{
    $path = '/var/app/storage/uploads/' . basename($filename);

    if (!file_exists($path)) {
        throw new NotFoundException('File not found');
    }

    $finfo = new \finfo(FILEINFO_MIME_TYPE);
    header('Content-Type: ' . $finfo->file($path));
    header('Content-Disposition: attachment; filename="' . basename($filename) . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
}
```

## Why

- **Random Filenames**: Prevents path traversal and overwrites
- **MIME Validation**: `finfo` checks actual file content, not client-provided type
- **Outside Web Root**: PHP files in uploads can't be executed directly
- **Size Limits**: Prevents disk exhaustion attacks
- **basename()**: Strips directory components from filenames to prevent traversal
- **Serve via Controller**: Allows access control checks before serving files
