<?php

namespace TrueQAP\FluentEmailPiping\Cron;

use TrueQAP\FluentEmailPiping\Email\Fetcher;
use TrueQAP\FluentEmailPiping\Webhook\Sender;

/**
 * Cron handler for checking emails periodically
 */
class EmailChecker {

    /**
     * Constructor
     */
    public function __construct() {
        add_action( 'fsep_check_emails', [ $this, 'checkAllAccounts' ] );
    }

    /**
     * Check all configured email accounts
     *
     * @return array Results summary
     */
    public function checkAllAccounts(): array {
        $accounts = get_option( 'fsep_email_accounts', [] );
        $settings = get_option( 'fsep_settings', [] );

        $results = [
            'total_accounts'  => count( $accounts ),
            'total_processed' => 0,
            'total_success'   => 0,
            'total_failed'    => 0,
            'accounts'        => [],
        ];

        foreach ( $accounts as $accountId => $account ) {
            if ( empty( $account['enabled'] ) ) {
                continue;
            }

            $accountResult = $this->checkAccount( $accountId, $account, $settings );
            $results['accounts'][ $accountId ] = $accountResult;
            $results['total_processed'] += $accountResult['processed'];
            $results['total_success'] += $accountResult['success'];
            $results['total_failed'] += $accountResult['failed'];
        }

        update_option( 'fsep_last_check', current_time( 'timestamp' ) );

        if ( ! empty( $settings['debug_mode'] ) ) {
            error_log( sprintf(
                '[Fluent Support Email Piping] Checked %d accounts, processed %d emails (%d success, %d failed)',
                $results['total_accounts'],
                $results['total_processed'],
                $results['total_success'],
                $results['total_failed']
            ) );
        }

        return $results;
    }

    /**
     * Check emails for a specific account (or all if null)
     *
     * @param int|null $accountId Specific account ID or null for all
     * @return array Results
     */
    public function checkEmails( ?int $accountId = null ): array {
        if ( $accountId !== null ) {
            $accounts = get_option( 'fsep_email_accounts', [] );
            $settings = get_option( 'fsep_settings', [] );

            if ( ! isset( $accounts[ $accountId ] ) ) {
                return [
                    'processed' => 0,
                    'success'   => 0,
                    'failed'    => 0,
                    'error'     => __( 'Account not found', 'fluent-support-email-piping' ),
                ];
            }

            return $this->checkAccount( $accountId, $accounts[ $accountId ], $settings );
        }

        return $this->checkAllAccounts();
    }

    /**
     * Process an email directly via ByMailHandler (no HTTP loopback).
     *
     * @param array $emailData Parsed email from Fetcher
     * @param \FluentSupport\App\Models\MailBox $mailbox
     * @return array{success: bool, message: string}
     */
    private function processEmailDirect( array $emailData, $mailbox ): array {
        $payload = Sender::buildPayload( $emailData );
        $mimeId  = $payload['mime_id'] ?? '';

        try {
            $response = \FluentSupportPro\App\Services\Integrations\FluentEmailPiping\ByMailHandler::processPayload(
                $payload,
                $mailbox,
                $mimeId
            );
        } catch ( \Throwable $e ) {
            return [
                'success' => false,
                'message' => 'Exception: ' . $e->getMessage(),
            ];
        }

        if ( is_wp_error( $response ) ) {
            return [
                'success' => false,
                'message' => $response->get_error_code() . ': ' . $response->get_error_message(),
            ];
        }

        if ( false === $response ) {
            return [
                'success' => true,
                'message' => 'Duplicate skipped',
            ];
        }

        if ( is_array( $response ) && isset( $response['type'] ) ) {
            return [
                'success' => true,
                'message' => $response['type'],
            ];
        }

        return [
            'success' => true,
            'message' => 'Processed',
        ];
    }

    /**
     * Check a single email account
     *
     * @param int $accountId
     * @param array $account
     * @param array $settings
     * @return array Results
     */
    private function checkAccount( int $accountId, array $account, array $settings ): array {
        $result = [
            'processed' => 0,
            'success'   => 0,
            'failed'    => 0,
            'errors'    => [],
        ];

        if ( empty( $account['host'] ) || empty( $account['username'] ) || empty( $account['password'] ) ) {
            $result['errors'][] = __( 'Incomplete account configuration', 'fluent-support-email-piping' );
            return $result;
        }

        $mailboxId = $account['mailbox_id'] ?? 0;

        if ( ! $mailboxId ) {
            $result['errors'][] = __( 'No Fluent Support mailbox assigned', 'fluent-support-email-piping' );
            return $result;
        }

        $canCallDirect = class_exists( '\\FluentSupportPro\\App\\Services\\Integrations\\FluentEmailPiping\\ByMailHandler' )
            && class_exists( '\\FluentSupport\\App\\Models\\MailBox' );

        $mailbox = null;
        if ( $canCallDirect ) {
            $mailbox = \FluentSupport\App\Models\MailBox::find( $mailboxId );
            if ( ! $mailbox ) {
                $result['errors'][] = __( 'Fluent Support mailbox not found', 'fluent-support-email-piping' );
                return $result;
            }
        } else {
            $webhookUrl = Sender::getWebhookUrlForMailbox( $mailboxId );
            if ( ! $webhookUrl ) {
                $result['errors'][] = __( 'Could not get webhook URL for mailbox', 'fluent-support-email-piping' );
                return $result;
            }
        }

        try {
            $fetcher = new Fetcher( $account );
            $limit = $account['fetch_limit'] ?? 10;
            $emails = $fetcher->fetchEmails( $limit );

            if ( empty( $emails ) ) {
                return $result;
            }

            $sender = null;
            if ( ! $canCallDirect ) {
                $sender = new Sender( $webhookUrl );
            }

            $deleteAfter = ! empty( $settings['delete_after_import'] );

            foreach ( $emails as $email ) {
                $result['processed']++;

                if ( $canCallDirect && $mailbox ) {
                    $sendResult = $this->processEmailDirect( $email, $mailbox );
                } else {
                    $sendResult = $sender->send( $email );
                }

                if ( $sendResult['success'] ) {
                    $result['success']++;

                    if ( $deleteAfter ) {
                        $fetcher->deleteEmail( $email['mail_id'] );
                    } else {
                        $fetcher->markAsRead( $email['mail_id'] );
                    }

                    $this->logProcessedEmail( $accountId, $email, 'success' );
                } else {
                    $result['failed']++;
                    $errorMsg = $sendResult['message'] ?? 'Unknown error';
                    $result['errors'][] = sprintf(
                        /* translators: 1: email subject, 2: error message */
                        __( 'Failed to process "%1$s": %2$s', 'fluent-support-email-piping' ),
                        $email['subject'] ?? 'Unknown',
                        $errorMsg
                    );

                    $this->logProcessedEmail( $accountId, $email, 'failed', $errorMsg );
                }
            }

            $fetcher->disconnect();

        } catch ( \Exception $e ) {
            $result['errors'][] = $e->getMessage();

            if ( ! empty( $settings['debug_mode'] ) ) {
                error_log( '[Fluent Support Email Piping] Error: ' . $e->getMessage() );
            }
        }

        return $result;
    }

    /**
     * Log processed email for history
     *
     * @param int $accountId
     * @param array $email
     * @param string $status
     * @param string $errorMessage
     */
    private function logProcessedEmail( int $accountId, array $email, string $status, string $errorMessage = '' ): void {
        $logs = get_option( 'fsep_email_logs', [] );

        // Keep only last 100 entries
        if ( count( $logs ) >= 100 ) {
            $logs = array_slice( $logs, -99 );
        }

        $logs[] = [
            'timestamp'     => current_time( 'timestamp' ),
            'account_id'    => $accountId,
            'message_id'    => $email['message_id'] ?? '',
            'subject'       => $email['subject'] ?? '',
            'from'          => $email['from']['value'][0]['address'] ?? '',
            'status'        => $status,
            'error_message' => $errorMessage,
        ];

        update_option( 'fsep_email_logs', $logs );
    }
}
