<?php

namespace TrueQAP\FluentEmailPiping\Webhook;

/**
 * Sends parsed emails to Fluent Support webhook
 */
class Sender {

    /**
     * @var string Webhook URL
     */
    private string $webhookUrl;

    /**
     * Constructor
     *
     * @param string $webhookUrl
     */
    public function __construct( string $webhookUrl ) {
        $this->webhookUrl = $webhookUrl;
    }

    /**
     * Send email data to Fluent Support webhook
     *
     * @param array $emailData Parsed email data
     * @return array Result with success status
     */
    public function send( array $emailData ): array {
        $payload = self::buildPayload( $emailData );

        $this->log( 'Sending to webhook: ' . $this->webhookUrl );
        $this->log( 'Payload: ' . wp_json_encode( $payload ) );

        $response = wp_remote_post( $this->webhookUrl, [
            'method'    => 'POST',
            'timeout'   => 30,
            'sslverify' => false,
            'headers'   => [
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'body'      => [
                'payload' => wp_json_encode( $payload ),
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            $errorMsg = 'WP HTTP Error: ' . $response->get_error_message();
            $this->log( $errorMsg );
            return [
                'success' => false,
                'message' => $errorMsg,
            ];
        }

        $responseCode = wp_remote_retrieve_response_code( $response );
        $responseBody = wp_remote_retrieve_body( $response );
        $responseData = json_decode( $responseBody, true );

        $this->log( 'Response code: ' . $responseCode );
        $this->log( 'Response body: ' . $responseBody );

        if ( $responseCode >= 200 && $responseCode < 300 ) {
            return [
                'success' => true,
                'data'    => $responseData,
            ];
        }

        $message = sprintf( 'HTTP %d', $responseCode );
        if ( is_array( $responseData ) && ! empty( $responseData['message'] ) ) {
            $message .= ': ' . $responseData['message'];
        } elseif ( ! empty( $responseBody ) ) {
            $message .= ': ' . mb_substr( strip_tags( $responseBody ), 0, 200 );
        }

        return [
            'success' => false,
            'message' => $message,
            'code'    => $responseCode,
        ];
    }

    /**
     * Log debug message
     *
     * @param string $message
     */
    private function log( string $message ): void {
        $settings = get_option( 'fsep_settings', [] );
        if ( ! empty( $settings['debug_mode'] ) ) {
            error_log( '[FSEP Sender] ' . $message );
        }
    }

    /**
     * Build a Fluent Support-compatible payload from parsed email data.
     *
     * @param array $emailData
     * @return array
     */
    public static function buildPayload( array $emailData ): array {
        $mimeId = ! empty( $emailData['message_id'] )
            ? md5( $emailData['message_id'] )
            : md5( uniqid( 'fsep_', true ) );

        return [
            'mime_id'     => $mimeId,
            'messageId'   => $emailData['message_id'] ?? '',
            'subject'     => $emailData['subject'] ?? '',
            'body_text'   => $emailData['body_text'] ?? '',
            'date'        => $emailData['date'] ?? current_time( 'mysql' ),
            'from'        => $emailData['from'] ?? [],
            'to'          => $emailData['to'] ?? [],
            'cc'          => $emailData['cc'] ?? [],
            'forwarded'   => $emailData['forwarded'] ?? null,
            'attachments' => self::formatAttachments( $emailData['attachments'] ?? [] ),
            'isMarkDown'  => $emailData['isMarkDown'] ?? false,
        ];
    }

    /**
     * Format attachments for the payload.
     *
     * @param array $attachments
     * @return array
     */
    private static function formatAttachments( array $attachments ): array {
        return array_map( static function ( $attachment ) {
            return [
                'filename'           => $attachment['filename'] ?? 'attachment',
                'url'                => $attachment['url'] ?? '',
                'contentType'        => $attachment['contentType'] ?? 'application/octet-stream',
                'contentDisposition' => $attachment['contentDisposition'] ?? 'attachment',
            ];
        }, $attachments );
    }

    /**
     * Get webhook URL for a specific mailbox
     *
     * @param int $mailboxId
     * @return string|null
     */
    public static function getWebhookUrlForMailbox( int $mailboxId ): ?string {
        if ( ! class_exists( '\\FluentSupport\\App\\Models\\MailBox' ) ) {
            return null;
        }

        $mailbox = \FluentSupport\App\Models\MailBox::find( $mailboxId );

        if ( ! $mailbox ) {
            return null;
        }

        // Get webhook token from meta
        $token = $mailbox->getMeta( '_webhook_token' );

        if ( ! $token ) {
            // Generate token if not exists
            $token = substr( md5( wp_generate_uuid4() ) . '_' . $mailbox->id . '_' . mt_rand( 100, 10000 ), 0, 16 );
            $mailbox->saveMeta( '_webhook_token', $token );
        }

        // Build webhook URL
        return rest_url( sprintf(
            'fluent-support/v2/mail-piping/%d/push/%s',
            $mailbox->id,
            $token
        ) );
    }

    /**
     * Get all available mailboxes with their webhook URLs
     *
     * @return array
     */
    public static function getAvailableMailboxes(): array {
        if ( ! class_exists( '\\FluentSupport\\App\\Models\\MailBox' ) ) {
            return [];
        }

        $mailboxes = \FluentSupport\App\Models\MailBox::where( 'box_type', 'email' )->get();

        $result = [];

        foreach ( $mailboxes as $mailbox ) {
            $result[] = [
                'id'          => $mailbox->id,
                'name'        => $mailbox->name,
                'email'       => $mailbox->email,
                'webhook_url' => self::getWebhookUrlForMailbox( $mailbox->id ),
            ];
        }

        return $result;
    }
}
