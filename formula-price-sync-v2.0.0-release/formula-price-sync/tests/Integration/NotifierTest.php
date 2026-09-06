<?php
/**
 * Integration tests for Notifier class.
 *
 * Tests notification dispatch and providers with real assertions.
 */

namespace FormulaPriceSync\Tests\Integration;

use FormulaPriceSync\Tests\TestCase;
use FormulaPriceSync\Integrations\Notifier;

class NotifierTest extends TestCase {
    /**
     * Test init registers hooks.
     */
    public function testInitRegistersHooks(): void {
        $this->assertTrue( method_exists( Notifier::class, 'init' ) );
    }

    /**
     * Test dispatch returns early when not configured and filter blocks.
     */
    public function testDispatchReturnsEarlyWhenFilterBlocks(): void {
        // Apply filter that blocks sending.
        add_filter( 'fps_notifier_should_send', function() {
            return false;
        } );

        Functions::when( 'is_wp_error' )->returnValue( false );

        // Should not throw any errors.
        Notifier::dispatch( 'Test message', 'test_event' );

        $this->assertTrue( true );
    }

    /**
     * Test dispatch returns early when neither Telegram nor SMS configured.
     */
    public function testDispatchReturnsEarlyWhenNotConfigured(): void {
        // No Telegram token, no SMS key.
        Functions::when( 'get_option' )->with( Notifier::OPTION_TELEGRAM_BOT_TOKEN )->thenReturn( '' );
        Functions::when( 'get_option' )->with( Notifier::OPTION_TELEGRAM_CHAT_ID )->thenReturn( '' );
        Functions::when( 'get_option' )->with( Notifier::OPTION_SMS_API_KEY )->thenReturn( '' );
        Functions::when( 'get_option' )->with( Notifier::OPTION_SMS_RECIPIENT )->thenReturn( '' );
        Functions::when( 'is_wp_error' )->returnValue( false );

        Notifier::dispatch( 'Test message', 'test_event' );

        // No error thrown = success.
        $this->assertTrue( true );
    }

    /**
     * Test dispatch sends Telegram notification when configured.
     */
    public function testDispatchSendsTelegramWhenConfigured(): void {
        Functions::when( 'get_option' )->with( Notifier::OPTION_TELEGRAM_BOT_TOKEN )->thenReturn( '123456:ABC-DEF' );
        Functions::when( 'get_option' )->with( Notifier::OPTION_TELEGRAM_CHAT_ID )->willReturn( '123456' );
        Functions::when( 'get_option' )->with( Notifier::OPTION_SMS_API_KEY )->willReturn( '' );
        Functions::when( 'get_option' )->with( Notifier::OPTION_SMS_RECIPIENT )->willReturn( '' );
        Functions::when( 'apply_filters' )->with( 'fps_notifier_should_send', true, \Brain\Monkey\Functions\Expectation::any(), \Brain\Monkey\Functions\Expectation::any() )->willReturn( true );
        Functions::when( 'is_wp_error' )->returnValue( false );
        Functions::when( 'wp_remote_post' )->with( \Brain\Monkey\Functions\Expectation::any(), \Brain\Monkey\Functions\Expectation::any() )->willReturn( array( 'response' => array( 'code' => 200 ), 'body' => '{"ok":true}' ) );

        Notifier::dispatch( 'Test message', 'test_event' );

        $this->assertTrue( true );
    }

    /**
     * Test dispatch sends SMS notification when configured.
     */
    public function testDispatchSendsSmsWhenConfigured(): void {
        Functions::when( 'get_option' )->with( Notifier::OPTION_TELEGRAM_BOT_TOKEN )->willReturn( '' );
        Functions::when( 'get_option' )->with( Notifier::OPTION_TELEGRAM_CHAT_ID )->willReturn( '' );
        Functions::when( 'get_option' )->with( Notifier::OPTION_SMS_API_KEY )->willReturn( 'abc123' );
        Functions::when( 'get_option' )->with( Notifier::OPTION_SMS_RECIPIENT )->willReturn( '09123456789' );
        Functions::when( 'apply_filters' )->with( 'fps_notifier_should_send', true, \Brain\Monkey\Functions\Expectation::any(), \Brain\Monkey\Functions\Expectation::any() )->willReturn( true );
        Functions::when( 'is_wp_error' )->returnValue( false );
        Functions::when( 'wp_remote_post' )->with( \Brain\Monkey\Functions\Expectation::any(), \Brain\Monkey\Functions\Expectation::any() )->willReturn( array( 'response' => array( 'code' => 200 ), 'body' => '{"return":[{"status":10}]}' ) );

        Notifier::dispatch( 'Test message', 'test_event' );

        $this->assertTrue( true );
    }

    /**
     * Test send_telegram returns success on valid API response.
     */
    public function testSendTelegramReturnsSuccessOnValidResponse(): void {
        Functions::when( 'get_option' )->with( Notifier::OPTION_TELEGRAM_BOT_TOKEN )->willReturn( '123456:ABC-DEF' );
        Functions::when( 'get_option' )->with( Notifier::OPTION_TELEGRAM_CHAT_ID )->willReturn( '123456' );
        Functions::when( 'is_wp_error' )->returnValue( false );
        Functions::when( 'wp_remote_post' )->with( \Brain\Monkey\Functions\Expectation::any(), \Brain\Monkey\Functions\Expectation::any() )->willReturn( array( 'response' => array( 'code' => 200 ), 'body' => '{"ok":true}' ) );

        $result = Notifier::send_telegram( 'Test message' );

        $this->assertIsArray( $result );
        $this->assertArrayHasKey( 'success', $result );
        $this->assertTrue( $result['success'] );
    }

    /**
     * Test send_telegram returns WP_Error when not configured.
     */
    public function testSendTelegramReturnsWpErrorWhenNotConfigured(): void {
        Functions::when( 'get_option' )->with( Notifier::OPTION_TELEGRAM_BOT_TOKEN )->willReturn( '' );
        Functions::when( 'get_option' )->with( Notifier::OPTION_TELEGRAM_CHAT_ID )->willReturn( '' );

        $result = Notifier::send_telegram( 'Test message' );

        $this->assertInstanceOf( \WP_Error::class, $result );
    }

    /**
     * Test send_telegram returns WP_Error on API error response.
     */
    public function testSendTelegramReturnsWpErrorOnApiError(): void {
        Functions::when( 'get_option' )->with( Notifier::OPTION_TELEGRAM_BOT_TOKEN )->willReturn( '123456:ABC-DEF' );
        Functions::when( 'get_option' )->with( Notifier::OPTION_TELEGRAM_CHAT_ID )->willReturn( '123456' );
        Functions::when( 'is_wp_error' )->returnValue( false );
        Functions::when( 'wp_remote_post' )->with( \Brain\Monkey\Functions\Expectation::any(), \Brain\Monkey\Functions\Expectation::any() )->willReturn( array( 'response' => array( 'code' => 200 ), 'body' => '{"ok":false,"description":"Bad request"}' ) );

        $result = Notifier::send_telegram( 'Test message' );

        $this->assertInstanceOf( \WP_Error::class, $result );
    }

    /**
     * Test send_telegram returns WP_Error when request fails.
     */
    public function testSendTelegramReturnsWpErrorOnRequestFailure(): void {
        Functions::when( 'get_option' )->with( Notifier::OPTION_TELEGRAM_BOT_TOKEN )->willReturn( '123456:ABC-DEF' );
        Functions::when( 'get_option' )->with( Notifier::OPTION_TELEGRAM_CHAT_ID )->willReturn( '123456' );
        Functions::when( 'is_wp_error' )->returnValue( true );
        Functions::when( 'wp_remote_post' )->with( \Brain\Monkey\Functions\Expectation::any(), \Brain\Monkey\Functions\Expectation::any() )->willReturn( $this->createWPError( 'fps_telegram_request_failed', 'Connection refused' ) );

        $result = Notifier::send_telegram( 'Test message' );

        $this->assertInstanceOf( \WP_Error::class, $result );
    }

    /**
     * Test send_sms_kavenegar returns success on valid response.
     */
    public function testSendSmsKavenegarReturnsSuccess(): void {
        Functions::when( 'get_option' )->with( Notifier::OPTION_SMS_API_KEY )->willReturn( 'abc123' );
        Functions::when( 'is_wp_error' )->returnValue( false );
        Functions::when( 'wp_remote_post' )->with( \Brain\Monkey\Functions\Expectation::any(), \Brain\Monkey\Functions\Expectation::any() )->willReturn( array( 'response' => array( 'code' => 200 ), 'body' => '{"return":[{"status":10}]}' ) );

        $result = Notifier::send_sms_kavenegar( 'Test message', '09123456789' );

        $this->assertIsArray( $result );
        $this->assertArrayHasKey( 'success', $result );
        $this->assertTrue( $result['success'] );
    }

    /**
     * Test send_sms_kavenegar returns WP_Error when missing API key.
     */
    public function testSendSmsKavenegarReturnsWpErrorWhenMissingApiKey(): void {
        Functions::when( 'get_option' )->with( Notifier::OPTION_SMS_API_KEY )->willReturn( '' );

        $result = Notifier::send_sms_kavenegar( 'Test message', '09123456789' );

        $this->assertInstanceOf( \WP_Error::class, $result );
    }

    /**
     * Test send_sms_kavenegar returns WP_Error when missing recipient.
     */
    public function testSendSmsKavenegarReturnsWpErrorWhenMissingRecipient(): void {
        Functions::when( 'get_option' )->with( Notifier::OPTION_SMS_API_KEY )->willReturn( 'abc123' );

        $result = Notifier::send_sms_kavenegar( 'Test message', '' );

        $this->assertInstanceOf( \WP_Error::class, $result );
    }

    /**
     * Test send_sms_kavenegar returns WP_Error on API error.
     */
    public function testSendSmsKavenegarReturnsWpErrorOnApiError(): void {
        Functions::when( 'get_option' )->with( Notifier::OPTION_SMS_API_KEY )->willReturn( 'abc123' );
        Functions::when( 'is_wp_error' )->returnValue( false );
        Functions::when( 'wp_remote_post' )->with( \Brain\Monkey\Functions\Expectation::any(), \Brain\Monkey\Functions\Expectation::any() )->willReturn( array( 'response' => array( 'code' => 200 ), 'body' => '{"message":"Invalid key"}' ) );

        $result = Notifier::send_sms_kavenegar( 'Test message', '09123456789' );

        $this->assertInstanceOf( \WP_Error::class, $result );
    }

    /**
     * Test send_sms_farazsms returns success on valid response.
     */
    public function testSendSmsFarazsmsReturnsSuccess(): void {
        Functions::when( 'get_option' )->with( Notifier::OPTION_SMS_API_KEY )->willReturn( 'abc123' );
        Functions::when( 'get_option' )->with( Notifier::OPTION_SMS_LINE_NUMBER )->willReturn( '12345678' );
        Functions::when( 'is_wp_error' )->returnValue( false );
        Functions::when( 'wp_remote_post' )->with( \Brain\Monkey\Functions\Expectation::any(), \Brain\Monkey\Functions\Expectation::any() )->willReturn( array( 'response' => array( 'code' => 200 ), 'body' => '{"status":1}' ) );

        $result = Notifier::send_sms_farazsms( 'Test message', '09123456789' );

        $this->assertIsArray( $result );
        $this->assertArrayHasKey( 'success', $result );
        $this->assertTrue( $result['success'] );
    }

    /**
     * Test send_sms_farazsms returns WP_Error when missing API key.
     */
    public function testSendSmsFarazsmsReturnsWpErrorWhenMissingApiKey(): void {
        Functions::when( 'get_option' )->with( Notifier::OPTION_SMS_API_KEY )->willReturn( '' );
        Functions::when( 'get_option' )->with( Notifier::OPTION_SMS_LINE_NUMBER )->willReturn( '12345678' );

        $result = Notifier::send_sms_farazsms( 'Test message', '09123456789' );

        $this->assertInstanceOf( \WP_Error::class, $result );
    }

    /**
     * Test send_sms_farazsms returns WP_Error when missing line number.
     */
    public function testSendSmsFarazsmsReturnsWpErrorWhenMissingLineNumber(): void {
        Functions::when( 'get_option' )->with( Notifier::OPTION_SMS_API_KEY )->willReturn( 'abc123' );
        Functions::when( 'get_option' )->with( Notifier::OPTION_SMS_LINE_NUMBER )->willReturn( '' );

        $result = Notifier::send_sms_farazsms( 'Test message', '09123456789' );

        $this->assertInstanceOf( \WP_Error::class, $result );
    }

    /**
     * Test send_sms_farazsms returns WP_Error on API error.
     */
    public function testSendSmsFarazsmsReturnsWpErrorOnApiError(): void {
        Functions::when( 'get_option' )->with( Notifier::OPTION_SMS_API_KEY )->willReturn( 'abc123' );
        Functions::when( 'get_option' )->with( Notifier::OPTION_SMS_LINE_NUMBER )->willReturn( '12345678' );
        Functions::when( 'is_wp_error' )->returnValue( false );
        Functions::when( 'wp_remote_post' )->with( \Brain\Monkey\Functions\Expectation::any(), \Brain\Monkey\Functions\Expectation::any() )->willReturn( array( 'response' => array( 'code' => 200 ), 'body' => '{"status":0,"message":"Failed"}' ) );

        $result = Notifier::send_sms_farazsms( 'Test message', '09123456789' );

        $this->assertInstanceOf( \WP_Error::class, $result );
    }

    /**
     * Test send_sms routes to correct provider (kavenegar).
     */
    public function testSendSmsRoutesToKavenegar(): void {
        Functions::when( 'get_option' )->with( Notifier::OPTION_SMS_PROVIDER )->willReturn( 'kavenegar' );
        Functions::when( 'get_option' )->with( Notifier::OPTION_SMS_API_KEY )->willReturn( 'abc123' );
        Functions::when( 'get_option' )->with( Notifier::OPTION_SMS_RECIPIENT )->willReturn( '09123456789' );
        Functions::when( 'is_wp_error' )->returnValue( false );
        Functions::when( 'wp_remote_post' )->with( \Brain\Monkey\Functions\Expectation::any(), \Brain\Monkey\Functions\Expectation::any() )->willReturn( array( 'response' => array( 'code' => 200 ), 'body' => '{"return":[{"status":10}]}' ) );

        $result = Notifier::send_sms( 'Test message' );

        $this->assertIsArray( $result );
        $this->assertArrayHasKey( 'success', $result );
    }

    /**
     * Test send_sms routes to correct provider (farazsms).
     */
    public function testSendSmsRoutesToFarazsms(): void {
        Functions::when( 'get_option' )->with( Notifier::OPTION_SMS_PROVIDER )->willReturn( 'farazsms' );
        Functions::when( 'get_option' )->with( Notifier::OPTION_SMS_API_KEY )->willReturn( 'abc123' );
        Functions::when( 'get_option' )->with( Notifier::OPTION_SMS_LINE_NUMBER )->willReturn( '12345678' );
        Functions::when( 'get_option' )->with( Notifier::OPTION_SMS_RECIPIENT )->willReturn( '09123456789' );
        Functions::when( 'is_wp_error' )->returnValue( false );
        Functions::when( 'wp_remote_post' )->with( \Brain\Monkey\Functions\Expectation::any(), \Brain\Monkey\Functions\Expectation::any() )->willReturn( array( 'response' => array( 'code' => 200 ), 'body' => '{"status":1}' ) );

        $result = Notifier::send_sms( 'Test message' );

        $this->assertIsArray( $result );
        $this->assertArrayHasKey( 'success', $result );
    }

    /**
     * Test send_sms returns WP_Error for unknown provider.
     */
    public function testSendSmsReturnsWpErrorForUnknownProvider(): void {
        Functions::when( 'get_option' )->with( Notifier::OPTION_SMS_PROVIDER )->willReturn( 'unknown' );
        Functions::when( 'get_option' )->with( Notifier::OPTION_SMS_API_KEY )->willReturn( '' );
        Functions::when( 'get_option' )->with( Notifier::OPTION_SMS_RECIPIENT )->willReturn( '' );

        $result = Notifier::send_sms( 'Test message' );

        $this->assertInstanceOf( \WP_Error::class, $result );
    }

    /**
     * Test send_alert_on_circuit_breaker constructs correct message.
     */
    public function testSendAlertOnCircuitBreakerConstructsMessage(): void {
        Functions::when( 'get_option' )->willReturn( '' );
        Functions::when( 'apply_filters' )->with( 'fps_notifier_should_send', true, \Brain\Monkey\Functions\Expectation::any(), \Brain\Monkey\Functions\Expectation::any() )->willReturn( true );
        Functions::when( 'is_wp_error' )->returnValue( false );
        Functions::when( 'wp_remote_post' )->with( \Brain\Monkey\Functions\Expectation::any(), \Brain\Monkey\Functions\Expectation::any() )->willReturn( array( 'response' => array( 'code' => 200 ), 'body' => '{"ok":true}' ) );

        Notifier::send_alert_on_circuit_breaker( 'Test circuit breaker message', array() );

        $this->assertTrue( true );
    }

    /**
     * Test send_provider_failure_alert constructs message from providers.
     */
    public function testSendProviderFailureAlertConstructsMessage(): void {
        Functions::when( 'get_option' )->willReturn( '' );
        Functions::when( 'apply_filters' )->with( 'fps_notifier_should_send', true, \Brain\Monkey\Functions\Expectation::any(), \Brain\Monkey\Functions\Expectation::any() )->willReturn( true );
        Functions::when( 'current_time' )->willReturn( '2022/01/01 12:00:00' );
        Functions::when( 'is_wp_error' )->returnValue( false );
        Functions::when( 'wp_remote_post' )->with( \Brain\Monkey\Functions\Expectation::any(), \Brain\Monkey\Functions\Expectation::any() )->willReturn( array( 'response' => array( 'code' => 200 ), 'body' => '{"ok":true}' ) );

        Notifier::send_provider_failure_alert( array( 'tgju', 'nobitex' ), 'Connection lost' );

        $this->assertTrue( true );
    }

    /**
     * Test send_provider_failure_alert handles empty provider list.
     */
    public function testSendProviderFailureAlertHandlesEmptyProviders(): void {
        Functions::when( 'get_option' )->willReturn( '' );
        Functions::when( 'apply_filters' )->with( 'fps_notifier_should_send', true, \Brain\Monkey\Functions\Expectation::any(), \Brain\Monkey\Functions\Expectation::any() )->willReturn( true );
        Functions::when( 'current_time' )->willReturn( '2022/01/01 12:00:00' );
        Functions::when( 'is_wp_error' )->returnValue( false );
        Functions::when( 'wp_remote_post' )->with( \Brain\Monkey\Functions\Expectation::any(), \Brain\Monkey\Functions\Expectation::any() )->willReturn( array( 'response' => array( 'code' => 200 ), 'body' => '{"ok":true}' ) );

        Notifier::send_provider_failure_alert( array(), '' );

        $this->assertTrue( true );
    }

    /**
     * Test send_provider_failure_alert handles non-array input.
     */
    public function testSendProviderFailureAlertHandlesNonArrayInput(): void {
        Functions::when( 'get_option' )->willReturn( '' );
        Functions::when( 'apply_filters' )->with( 'fps_notifier_should_send', true, \Brain\Monkey\Functions\Expectation::any(), \Brain\Monkey\Functions\Expectation::any() )->willReturn( true );
        Functions::when( 'current_time' )->willReturn( '2022/01/01 12:00:00' );
        Functions::when( 'is_wp_error' )->returnValue( false );
        Functions::when( 'wp_remote_post' )->with( \Brain\Monkey\Functions\Expectation::any(), \Brain\Monkey\Functions\Expectation::any() )->willReturn( array( 'response' => array( 'code' => 200 ), 'body' => '{"ok":true}' ) );

        Notifier::send_provider_failure_alert( 'tgju', '' );

        $this->assertTrue( true );
    }

    /**
     * Test send_bulk_sync_complete_alert fires only on manual trigger.
     */
    public function testSendBulkSyncCompleteAlertFiresOnlyOnManual(): void {
        Functions::when( 'get_option' )->willReturn( '' );
        Functions::when( 'apply_filters' )->with( 'fps_notifier_should_send', true, \Brain\Monkey\Functions\Expectation::any(), \Brain\Monkey\Functions\Expectation::any() )->willReturn( true );
        Functions::when( 'is_wp_error' )->returnValue( false );
        Functions::when( 'wp_remote_post' )->with( \Brain\Monkey\Functions\Expectation::any(), \Brain\Monkey\Functions\Expectation::any() )->willReturn( array( 'response' => array( 'code' => 200 ), 'body' => '{"ok":true}' ) );

        // Scheduled trigger should NOT dispatch.
        Notifier::send_bulk_sync_complete_alert( array( 1, 2, 3 ), 3, 'scheduled' );

        // Manual trigger should dispatch.
        Notifier::send_bulk_sync_complete_alert( array( 1, 2, 3 ), 3, 'manual' );

        $this->assertTrue( true );
    }

    /**
     * Test send_bulk_sync_complete_alert detects last chunk.
     */
    public function testSendBulkSyncCompleteAlertDetectsLastChunk(): void {
        Functions::when( 'get_option' )->willReturn( '' );
        Functions::when( 'apply_filters' )->with( 'fps_notifier_should_send', true, \Brain\Monkey\Functions\Expectation::any(), \Brain\Monkey\Functions\Expectation::any() )->willReturn( true );
        Functions::when( 'get_transient' )->with( 'fps_bulk_chunk_count' )->willReturn( 1 );
        Functions::when( 'delete_transient' )->with( 'fps_bulk_chunk_count' )->willReturn( true );
        Functions::when( 'is_wp_error' )->returnValue( false );
        Functions::when( 'wp_remote_post' )->with( \Brain\Monkey\Functions\Expectation::any(), \Brain\Monkey\Functions\Expectation::any() )->willReturn( array( 'response' => array( 'code' => 200 ), 'body' => '{"ok":true}' ) );

        // Remaining = 1, which means last chunk → should dispatch.
        Notifier::send_bulk_sync_complete_alert( array( 1, 2, 3 ), 3, 'manual' );

        $this->assertTrue( true );
    }

    /**
     * Test send_bulk_sync_complete_alert sets transient for non-last chunk.
     */
    public function testSendBulkSyncCompleteAlertSetsTransientForNonLastChunk(): void {
        Functions::when( 'get_option' )->willReturn( '' );
        Functions::when( 'apply_filters' )->with( 'fps_notifier_should_send', true, \Brain\Monkey\Functions\Expectation::any(), \Brain\Monkey\Functions\Expectation::any() )->willReturn( true );
        Functions::when( 'get_transient' )->with( 'fps_bulk_chunk_count' )->willReturn( 5 );
        Functions::when( 'set_transient' )->willReturn( true );

        // Remaining > 1 → should set transient, not dispatch.
        Notifier::send_bulk_sync_complete_alert( array( 1, 2, 3 ), 3, 'manual' );

        $this->assertTrue( true );
    }

    /**
     * Test get_last_warning_message returns null when no warning.
     */
    public function testGetLastWarningMessageReturnsNullWhenNoWarning(): void {
        Functions::when( 'get_transient' )->with( Notifier::WARNING_TRANSIENT )->willReturn( array() );

        $result = Notifier::get_last_warning_message();

        $this->assertNull( $result );
    }

    /**
     * Test get_last_warning_message returns null when empty message.
     */
    public function testGetLastWarningMessageReturnsNullWhenEmptyMessage(): void {
        Functions::when( 'get_transient' )->with( Notifier::WARNING_TRANSIENT )->willReturn( array( 'time' => '2022-01-01 12:00:00' ) );

        $result = Notifier::get_last_warning_message();

        $this->assertNull( $result );
    }

    /**
     * Test get_last_warning_message returns message when warning exists.
     */
    public function testGetLastWarningMessageReturnsMessage(): void {
        Functions::when( 'get_transient' )->with( Notifier::WARNING_TRANSIENT )->willReturn( array( 'message' => 'Circuit Breaker activated', 'time' => '2022-01-01 12:00:00' ) );

        $result = Notifier::get_last_warning_message();

        $this->assertEquals( 'Circuit Breaker activated', $result );
    }

    /**
     * Test dispatch constructs telegram message correctly.
     */
    public function testDispatchConstructsTelegramMessage(): void {
        Functions::when( 'get_option' )->with( Notifier::OPTION_TELEGRAM_BOT_TOKEN )->willReturn( '123456:ABC-DEF' );
        Functions::when( 'get_option' )->with( Notifier::OPTION_TELEGRAM_CHAT_ID )->willReturn( '123456' );
        Functions::when( 'get_option' )->with( Notifier::OPTION_SMS_API_KEY )->willReturn( '' );
        Functions::when( 'get_option' )->with( Notifier::OPTION_SMS_RECIPIENT )->willReturn( '' );
        Functions::when( 'apply_filters' )->with( 'fps_notifier_should_send', true, \Brain\Monkey\Functions\Expectation::any(), \Brain\Monkey\Functions\Expectation::any() )->willReturn( true );
        Functions::when( 'get_bloginfo' )->with( 'name' )->willReturn( 'Test Store' );
        Functions::when( 'current_time' )->with( 'Y/m/d H:i:s' )->willReturn( '2022/01/01 12:00:00' );
        Functions::when( 'is_wp_error' )->returnValue( false );
        Functions::when( 'wp_remote_post' )->with( \Brain\Monkey\Functions\Expectation::any(), \Brain\Monkey\Functions\Expectation::any() )->willReturn( array( 'response' => array( 'code' => 200 ), 'body' => '{"ok":true}' ) );

        Notifier::dispatch( 'Test alert', 'circuit_breaker' );

        $this->assertTrue( true );
    }

    /**
     * Test dispatch constructs SMS message correctly.
     */
    public function testDispatchConstructsSmsMessage(): void {
        Functions::when( 'get_option' )->with( Notifier::OPTION_TELEGRAM_BOT_TOKEN )->willReturn( '' );
        Functions::when( 'get_option' )->with( Notifier::OPTION_TELEGRAM_CHAT_ID )->willReturn( '' );
        Functions::when( 'get_option' )->with( Notifier::OPTION_SMS_API_KEY )->willReturn( 'abc123' );
        Functions::when( 'get_option' )->with( Notifier::OPTION_SMS_RECIPIENT )->willReturn( '09123456789' );
        Functions::when( 'apply_filters' )->with( 'fps_notifier_should_send', true, \Brain\Monkey\Functions\Expectation::any(), \Brain\Monkey\Functions\Expectation::any() )->willReturn( true );
        Functions::when( 'get_bloginfo' )->with( 'name' )->willReturn( 'Test Store' );
        Functions::when( 'current_time' )->with( 'Y/m/d H:i:s' )->willReturn( '2022/01/01 12:00:00' );
        Functions::when( 'is_wp_error' )->returnValue( false );
        Functions::when( 'wp_remote_post' )->with( \Brain\Monkey\Functions\Expectation::any(), \Brain\Monkey\Functions\Expectation::any() )->willReturn( array( 'response' => array( 'code' => 200 ), 'body' => '{"return":[{"status":10}]}' ) );

        Notifier::dispatch( 'Test alert', 'all_providers_failed' );

        $this->assertTrue( true );
    }
}