# EmailIt BBPress Integration

A WordPress plugin that integrates BBPress with the EmailIt Mailer system to provide enhanced email notifications for forum activities.

## Description

EmailIt BBPress Integration enhances your BBPress forum's email notification system by leveraging the EmailIt Mailer service. This plugin replaces the default BBPress email notification system with a more robust solution that includes:

- HTML email templates with responsive design
- Plain text email fallback support
- Batched email processing for better performance
- Custom branding support with your site logo
- Optimized handling of forum and topic notifications

## Requirements

- WordPress 5.8 or higher
- PHP 7.4 or higher
- BBPress plugin (latest version recommended)
- EmailIt Mailer plugin (latest version required)
- Active EmailIt API key and configured sending domain

## Installation

1. Upload the `emailit-bbpress` directory to your `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Ensure both BBPress and EmailIt Mailer plugins are installed and activated
4. Configure your EmailIt Mailer settings if you haven't already

## Features

### Forum Notifications

- **New Topic Notifications**: Subscribers of a forum receive beautifully formatted email notifications when new topics are created
- **New Reply Notifications**: Topic subscribers receive notifications when new replies are posted
- **Batch Processing**: Emails are sent in batches to prevent server overload
- **Custom Branding**: Automatically includes your site logo in email templates

### Email Features

- Responsive HTML email templates
- Plain text fallback for email clients that don't support HTML
- Customized subject lines including forum and topic information
- Unsubscribe instructions included in every email
- Proper email headers and formatting

### Performance Optimization

- Emails are processed in batches of 10 recipients
- Cron-based scheduling to prevent timeout issues
- Efficient handling of large subscriber lists
- Prevents duplicate notifications to post authors

## Configuration

The plugin integrates seamlessly with your existing EmailIt Mailer settings. No additional configuration is required beyond your basic EmailIt setup.

To access settings:

1. Go to WordPress Admin
2. Navigate to Settings → EmailIt Mailer
3. Select the "BBPress" tab
4. (Future updates will include customizable email templates)

## Technical Details

### Email Processing

Emails are processed in batches with the following characteristics:
- Batch size: 10 recipients per batch
- Processing interval: 1 minute between batches
- Scheduled via WordPress cron system

### Template System

The plugin includes two types of templates:
- HTML template with responsive design
- Plain text fallback template

Both templates include:
- Forum title
- Topic title
- Author information
- Post content
- Direct links to the content
- Unsubscribe instructions

### EmailIt SDK v2 Integration (Jan 2026)

The plugin now targets the EmailIt PHP SDK v2 release (Jan 31, 2026 build):

- Uses `EmailItClient::sendEmail()` with the v2 `/emails` endpoint and the fluent `EmailBuilder` helpers when available
- Pulls account metadata via the new `DomainManager` (`EmailItClient::domains()`) and `ApiKeyManager` (`EmailItClient::apiKeys()`) accessors to capture verified domain and API key label context
- Normalizes TO/CC/BCC recipients (deduped) before dispatch and exposes filters for custom audiences:
  - `emailit_bbpress_bcc_addresses`
  - `emailit_bbpress_cc_addresses`
  - `emailit_bbpress_sdk_payload`
  - `emailit_bbpress_account_cache`
- Schedules batches on the dedicated `emailit_bbpress_process_batch` cron hook to keep legacy queues untouched while providing a fallback that still proxies to the historic `emailit_process_email_batch` action when the new SDK is not present
- Automatically augments payload metadata with `emailit_account` details (verified domain, API key label) for downstream observability

These updates remove the dependency on deprecated `sendingDomains()` / `credentials()` accessors, eliminate the `sendEmailRequest` helper, and ensure BCC lists are fully supported end-to-end.

## Troubleshooting

### Common Issues

1. **Emails Not Sending**
   - Verify EmailIt Mailer is properly configured
   - Check API key status
   - Confirm sending domain verification
   - Enable WP_DEBUG for detailed logging

2. **Missing Logo in Emails**
   - Upload a site logo in WordPress Customizer
   - Verify logo is properly set as site logo

3. **Plugin Activation Errors**
   - Ensure all dependencies are installed and activated
   - Verify PHP version requirements
   - Check WordPress version compatibility

## Support

For support inquiries:
- Check plugin documentation
- Contact your EmailIt service provider
- Submit issues through the plugin support channels

## Changelog

### 1.7
- Current stable release
- Includes batch processing
- HTML email templates
- BBPress integration tab

## License

This plugin is licensed under GPL2.

## Credits

Developed by Steven Gauerke