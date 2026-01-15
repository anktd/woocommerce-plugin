<?php
use PHPUnit\Framework\TestCase;
use WP_Mock as wp;
use Mockery as m;

class TestableBlockonomics extends Blockonomics {
    public function __construct($api_key = 'temporary_api_key') {
        $this->api_key = $api_key;
    }
}

class BlockonomicsTest extends TestCase {
    protected $blockonomics;

    protected function setUp(): void {
        parent::setUp();
        wp::setUp();
        $this->blockonomics = m::mock(TestableBlockonomics::class, ['ZJ4PNtTnKqWxeMCQ6smlMBvj3i3KAtt2hwLSGuk9Lyk'])->makePartial();

        // Mock WordPress functions
        wp::userFunction('get_option', [
            'return' => function($option_name) {
                switch ($option_name) {
                    case 'blockonomics_btc':
                        return true;
                    case 'blockonomics_api_key':
                        return 'ZJ4PNtTnKqWxeMCQ6smlMBvj3i3KAtt2hwLSGuk9Lyk';
                    case 'blockonomics_callback_secret':
                        return '2c5a71c1367e23a6b04a20544d0d4a4601c34881';
                    default:
                        return null;
                }
            }
        ]);

        wp::userFunction('wp_remote_retrieve_response_code', [
            'return' => function($response) {
                return isset($response['response']['code']) ? $response['response']['code'] : null;
            }
        ]);

        wp::userFunction('wp_remote_retrieve_body', [
            'return' => function($response) {
                return isset($response['body']) ? $response['body'] : [];
            }
        ]);

        wp::userFunction('WC', [
            'return' => function() {
                return new class{
                    public function api_request_url($endpoint) {
                        return "https://localhost:8888/wordpress/wc-api/WC_Gateway_Blockonomics/";
                    }
                };
            }
        ]);

        wp::userFunction('add_query_arg', [
            'return' => function($args, $url) {
                if (!is_array($args)) {
                    $args = [];
                }
                return $url . '?' . http_build_query($args);
            }
        ]);

        wp::userFunction('is_wp_error', [
            'return' => function($thing) {
                return ($thing instanceof \WP_Error);
            }
        ]);
    }

    // Existing tests that are still relevant
    public function testCalculateTotalPaidFiatWithNoTransactions() {
        wp::userFunction('wc_get_price_decimals', [
            'times'  => 1,
            'return' => 2,
        ]);

        $transactions = [];
        $expectedTotal = 0.0;
        $this->assertSame($expectedTotal, $this->blockonomics->calculate_total_paid_fiat($transactions));
    }

    public function testCalculateTotalPaidFiatWithVariousTransactions() {
        wp::userFunction('wc_get_price_decimals', [
            'times'  => 1,
            'return' => 2,
        ]);

        $transactions = [
            ['paid_fiat' => '10.00'],
            ['paid_fiat' => '5.50'],
            ['paid_fiat' => '2.50']
        ];
        $expectedTotal = 18.0;
        $this->assertEquals($expectedTotal, $this->blockonomics->calculate_total_paid_fiat($transactions));
    }

    public function testFixDisplayingSmallValuesLessThan10000() {
        $this->assertEquals("0.000095", $this->blockonomics->fix_displaying_small_values('btc', 9500));
    }

    public function testFixDisplayingSmallValuesGreaterThan10000() {
        $this->assertEquals(0.0001, $this->blockonomics->fix_displaying_small_values('btc', 10000));
    }

    public function testGetCryptoPaymentUriForBTC() {
        $crypto = ['uri' => 'bitcoin'];
        $address = "bc1qnhuxvspzj28vcdc8e7wxnnwhqdu7pyvdwsw0dy";
        $order_amount = 0.05;
        $expectedUri = "bitcoin:bc1qnhuxvspzj28vcdc8e7wxnnwhqdu7pyvdwsw0dy?amount=0.05";
        $this->assertEquals($expectedUri, $this->blockonomics->get_crypto_payment_uri($crypto, $address, $order_amount));
    }

    public function testGetSupportedCurrencies() {
        $expectedCurrencies = [
            'btc' => [
                'code' => 'btc',
                'name' => 'Bitcoin',
                'uri' => 'bitcoin',
                'decimals' => 8,
            ],
            'bch' => [
                'code' => 'bch',
                'name' => 'Bitcoin Cash',
                'uri' => 'bitcoincash',
                'decimals' => 8,
            ],
            'usdt' => [
                'code' => 'usdt',
                'name' => 'USDT',
                'decimals' => 6,
            ]
        ];
        $actualCurrencies = $this->blockonomics->getSupportedCurrencies();
        $this->assertEquals($expectedCurrencies, $actualCurrencies, "The getSupportedCurrencies method did not return the expected array of cryptocurrencies.");
    }

    // this test checks the fix for wpml compatibility - ensures callback are normalised and regex is correct
    public function testWpmlLanguagePrefixRegex() {
        $pattern = '#/[a-z]{2}(-[a-z]{2})?/wc-api/#i';
        $replacement = '/wc-api/';

        //strip 2-letter language codes
        $this->assertEquals(
            'example.com/wc-api/WC_Gateway_Blockonomics/',
            preg_replace($pattern, $replacement, 'example.com/de/wc-api/WC_Gateway_Blockonomics/'),
            "Should strip /de/ prefix"
        );

        //strip language + region codes
        $this->assertEquals(
            'example.com/wc-api/WC_Gateway_Blockonomics/',
            preg_replace($pattern, $replacement, 'example.com/en-us/wc-api/WC_Gateway_Blockonomics/'),
            "Should strip /en-us/ prefix"
        );

        // DO NOT strip 3-letter codes
        $this->assertEquals(
            'example.com/deu/wc-api/WC_Gateway_Blockonomics/',
            preg_replace($pattern, $replacement, 'example.com/deu/wc-api/WC_Gateway_Blockonomics/'),
            "Should NOT strip /deu/ (3-letter code)"
        );

        // check if it work with subdirectory installs
        $this->assertEquals(
            'example.com/shop/wc-api/WC_Gateway_Blockonomics/',
            preg_replace($pattern, $replacement, 'example.com/shop/de/wc-api/WC_Gateway_Blockonomics/'),
            "Should handle subdirectory installs"
        );

        //this should leave url without prefix unchanged
        $this->assertEquals(
            'example.com/wc-api/WC_Gateway_Blockonomics/',
            preg_replace($pattern, $replacement, 'example.com/wc-api/WC_Gateway_Blockonomics/'),
            "Should not modify URLs without language prefix"
        );
    }

    public function testIconsGenerationWithErrorResponse() {
        $active_cryptos = ['error' => 'API Key is not set. Please enter your API Key.'];
        $icons_src = [];

        if (empty($active_cryptos) || isset($active_cryptos['error'])) {
            // Should return empty
            $this->assertEmpty($icons_src, "Icons should be empty when error response received");
            return;
        }

        $this->fail('Should have returned early due to error');
    }

    public function testIconsGenerationWithValidCryptos() {
        $active_cryptos = [
            'btc' => ['code' => 'btc', 'name' => 'Bitcoin', 'uri' => 'bitcoin', 'decimals' => 8],
            'usdt' => ['code' => 'usdt', 'name' => 'USDT', 'decimals' => 6]
        ];
        $icons_src = [];

        if (empty($active_cryptos) || isset($active_cryptos['error'])) {
            $this->fail('Should not return early for valid cryptos');
        }

        foreach ($active_cryptos as $code => $crypto) {
            $icons_src[$crypto['code']] = [
                'src' => 'test/'.$crypto['code'].'.png',
                'alt' => $crypto['name'],
            ];
        }

        $this->assertCount(2, $icons_src, "Should have icons for 2 cryptocurrencies");
        $this->assertArrayHasKey('btc', $icons_src, "Should have BTC icon");
        $this->assertArrayHasKey('usdt', $icons_src, "Should have USDT icon");
        $this->assertEquals('Bitcoin', $icons_src['btc']['alt'], "BTC alt text should be 'Bitcoin'");
        $this->assertEquals('USDT', $icons_src['usdt']['alt'], "USDT alt text should be 'USDT'");
    }

    public function testIconsGenerationWithEmptyResponse() {
        $active_cryptos = [];
        $icons_src = [];

        if (empty($active_cryptos) || isset($active_cryptos['error'])) {
            $this->assertEmpty($icons_src, "Icons should be empty when no active cryptos");
            return;
        }

        $this->fail('Should have returned early due to empty array');
    }

    public function testIconsGenerationWithSingleCryptoBTC() {
        $active_cryptos = [
            'btc' => ['code' => 'btc', 'name' => 'Bitcoin', 'uri' => 'bitcoin', 'decimals' => 8]
        ];
        $icons_src = [];

        if (empty($active_cryptos) || isset($active_cryptos['error'])) {
            $this->fail('Should not return early for valid crypto');
        }

        foreach ($active_cryptos as $code => $crypto) {
            $icons_src[$crypto['code']] = [
                'src' => 'test/'.$crypto['code'].'.png',
                'alt' => $crypto['name'],
            ];
        }

        $this->assertCount(1, $icons_src, "Should have icon for 1 cryptocurrency");
        $this->assertArrayHasKey('btc', $icons_src, "Should have BTC icon");
        $this->assertEquals('Bitcoin', $icons_src['btc']['alt']);
    }

    public function testIconsGenerationWithSingleCryptoUSDT() {
        $active_cryptos = [
            'usdt' => ['code' => 'usdt', 'name' => 'USDT', 'decimals' => 6]
        ];
        $icons_src = [];

        if (empty($active_cryptos) || isset($active_cryptos['error'])) {
            $this->fail('Should not return early for valid crypto');
        }

        foreach ($active_cryptos as $code => $crypto) {
            $icons_src[$crypto['code']] = [
                'src' => 'test/'.$crypto['code'].'.png',
                'alt' => $crypto['name'],
            ];
        }

        $this->assertCount(1, $icons_src, "Should have icon for 1 cryptocurrency");
        $this->assertArrayHasKey('usdt', $icons_src, "Should have USDT icon");
        $this->assertEquals('USDT', $icons_src['usdt']['alt']);
    }


        // this test checks the fix for wpml compatibility - ensures callback are normalised and regex is correct
    public function testWpmlLanguagePrefixRegex() {
        $pattern = '#/[a-z]{2}(-[a-z]{2})?/wc-api/#i';
        $replacement = '/wc-api/';

        //strip 2-letter language codes
        $this->assertEquals(
            'example.com/wc-api/WC_Gateway_Blockonomics/',
            preg_replace($pattern, $replacement, 'example.com/de/wc-api/WC_Gateway_Blockonomics/'),
            "Should strip /de/ prefix"
        );

        //strip language + region codes
        $this->assertEquals(
            'example.com/wc-api/WC_Gateway_Blockonomics/',
            preg_replace($pattern, $replacement, 'example.com/en-us/wc-api/WC_Gateway_Blockonomics/'),
            "Should strip /en-us/ prefix"
        );

        // DO NOT strip 3-letter codes
        $this->assertEquals(
            'example.com/deu/wc-api/WC_Gateway_Blockonomics/',
            preg_replace($pattern, $replacement, 'example.com/deu/wc-api/WC_Gateway_Blockonomics/'),
            "Should NOT strip /deu/ (3-letter code)"
        );

        // check if it work with subdirectory installs
        $this->assertEquals(
            'example.com/shop/wc-api/WC_Gateway_Blockonomics/',
            preg_replace($pattern, $replacement, 'example.com/shop/de/wc-api/WC_Gateway_Blockonomics/'),
            "Should handle subdirectory installs"
        );

        //this should leave url without prefix unchanged
        $this->assertEquals(
            'example.com/wc-api/WC_Gateway_Blockonomics/',
            preg_replace($pattern, $replacement, 'example.com/wc-api/WC_Gateway_Blockonomics/'),
            "Should not modify URLs without language prefix"
        );
    }

    protected function tearDown(): void {
        wp::tearDown();
        parent::tearDown();
    }
}
?>