<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

/**
 * English translations — message texts
 *
 * Keys are organized by module prefix:
 *   auth.*      Authentication
 *   captcha.*   CAPTCHA
 *   crud.*      CRUD operations
 *   profile.*   User profile
 *   batch.*     Batch operations
 *   security.*  Security / permissions
 *   upload.*    Upload / import
 *   generic.*   Generic messages
 */

return [
    // Auth
    'login_success' => 'Login successful',
    'register_success' => 'Registration successful',
    'logout_success' => 'Logged out',
    'invalid_credentials' => 'Invalid username or password',
    'account_disabled' => 'Account has been disabled',
    'account_locked' => 'Account temporarily locked, please try again in 15 minutes',
    'token_expired' => 'Token expired or invalid',
    'token_invalid' => 'Token is invalid, please log in again',
    'not_logged_in' => 'Not logged in',
    'refresh_invalid' => 'Refresh token is invalid or has expired',
    'refresh_missing' => 'Refresh token is missing',
    'username_exists' => 'Username already exists',

    // Captcha
    'captcha_error' => 'CAPTCHA error, please try again',
    'captcha_generate_failed' => 'CAPTCHA generation failed',
    'captcha_missing' => 'CAPTCHA parameters are missing',
    'captcha_verify_pass' => 'Verification passed',
    'captcha_verify_fail' => 'Verification failed, please try again',

    // CRUD
    'create_success' => 'Created successfully',
    'update_success' => 'Updated successfully',
    'delete_success' => 'Deleted successfully',
    'not_found' => 'Resource not found',
    'already_exists' => 'Record already exists',
    'user_not_found' => 'User not found',
    'config_not_found' => 'Configuration item not found',
    'config_exists' => 'Configuration item already exists',

    // Profile
    'profile_updated' => 'Profile updated successfully',
    'password_changed' => 'Password changed successfully',
    'old_password_wrong' => 'Old password is incorrect',
    'password_too_short' => 'New password must be 6–32 characters',
    'password_required' => 'Please enter both old and new password',

    // Batch
    'batch_delete_success' => 'Batch delete successful',
    'batch_enable_success' => 'Batch enable successful',
    'batch_disable_success' => 'Batch disable successful',
    'no_selection' => 'Please select',
    'no_user_selection' => 'Please select users to delete',
    'no_user_selection_status' => 'Please select users',
    'invalid_status' => 'Invalid status value',
    'invalid_ids' => 'Invalid IDs',

    // Security
    'rate_limited' => 'Too many requests, please try again later',
    'permission_denied' => 'Access denied',
    'forbidden' => '403 Forbidden',
    'payload_too_large' => '413 Payload Too Large',
    'unsupported_media' => '415 Unsupported Media Type',
    'method_not_allowed' => '405 Method Not Allowed',
    'api_version_unsupported' => 'Unsupported API version',
    'password_confirm_required' => 'Password confirmation required for sensitive operations',
    'password_confirm_failed' => 'Password verification failed',

    // Upload/Import
    'upload_success' => 'Upload successful',
    'upload_no_file' => 'Please select a file',
    'upload_failed' => 'File upload failed',
    'upload_invalid_type' => 'Unsupported file type',
    'upload_too_large' => 'File size cannot exceed 10MB',
    'import_complete' => 'Import complete',
    'import_no_data' => 'Excel file contains no data',
    'import_missing_column' => 'Required column is missing',
    'import_invalid_file' => 'Only .xlsx or .xls files are supported',

    // Generic
    'success' => 'success',
    'error' => 'error',
    'server_error' => 'Internal server error',
    'validation_failed' => 'Parameter validation failed',
];
