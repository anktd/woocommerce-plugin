<?php
$blockonomics = new Blockonomics;
$cryptos = $blockonomics->getActiveCurrencies();
$order_id = isset($_REQUEST["select_crypto"]) ? sanitize_text_field(wp_unslash($_REQUEST["select_crypto"])) : "";
$order_url = $blockonomics->get_parameterized_wc_url('page',array('show_order'=>$order_id))
?>
<div class="woocommerce bnomics-order-container">
  <div class="bnomics-select-container">
    <?php
    foreach ($cryptos as $code => $crypto) {
      $order_url = add_query_arg('crypto', $code, $order_url);
    ?>
      <a href="<?php echo $order_url;?>">
        <button class="bnomics-select-options woocommerce-button button">
          <span class="bnomics-icon-<?php echo $code;?>"></span>
          <span><?=__('Pay with', 'blockonomics-bitcoin-payments')?> <?php echo $crypto['name'];?></span>
        </button>
      </a>
    <?php
    }
    ?>
    <div class="bnomics-powered-by">
      <a href="https://www.blockonomics.co" target="_blank" rel="noopener">
        <?=__('Powered by', 'blockonomics-bitcoin-payments')?> <span class="bnomics-brand">Blockonomics</span>
      </a>
    </div>
  </div>
</div>
