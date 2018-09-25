<div class="container">
  <div class="py-5 text-center">
    <h2>订单付款</h2>
    <div class="alert alert-success pay-ok pay-ok-alert" role="alert" style="display: none">
      <a href="#" class="alert-link">付款已成功</a>
    </div>
  </div>

  <div class="row">
    <div class="col-md-2"></div>
    <div class="col-md-4">
      <h4 class="d-flex justify-content-between align-items-center mb-3 text-center">
        <span class="text-muted">订单信息</span>
      </h4>
      <ul class="list-group mb-3">
        <li class="list-group-item d-flex justify-content-between lh-condensed">
          <div>
            <h6 class="my-0">订单号</h6>
            <!--                        <small class="text-muted">Brief description</small>-->
          </div>
          <span class="text-muted"><span><?php echo $data['order']['order_no']; ?></span>
        </li>
        <li class="list-group-item d-flex justify-content-between lh-condensed">
          <div>
            <h6 class="my-0">金额</h6>
            <!--                        <small class="text-muted">Brief description</small>-->
          </div>
          <span class="text-muted">￥<span><?php echo $data['order']['amount']; ?>元</span>
        </li>
        <li class="list-group-item d-flex justify-content-between lh-condensed pay-ok pay-ok-li">
          <button class="btn btn-primary btn-lg btn-block pay-btn " type="submit">付款中...</button>
        </li>
        <li class="list-group-item d-flex justify-content-between lh-condensed pay-ok pay-ok-li">
          <div class="alert alert-warning" role="alert">二维码只能使用一次,请不要多次支付</div>
        </li>
      </ul>
    </div>
    <div class="col-md-4">
      <h4 class="mb-3" style="text-align: center;"><?php echo $data['order']['pay_method_str']; ?>付款</h4>
      <form class="needs-validation" novalidate>
        <div class="row" style="text-align: center">
          <!--                  <img style="width:300px;height:300px;" src="http://qr.liantu.com/api.php?text=-->
            <?php //echo urlencode($data['data']['qr']); ?><!--"/>-->
          <div id="qrcode" style="width: 100%">
            <img id="qrcode-img" style="width:300px;height:300px;display: none" src=""/>
          </div>
        </div>
      </form>
    </div>
    <div class="col-md-2"></div>
  </div>

  <footer class="my-5 pt-5 text-muted text-center text-small">
    <p class="mb-1">&copy; <?php echo date('Y') ?> <a href="<?php echo $data['data']['qr']; ?>" id="qr_link"
                                                      target="_blank" style=""></a></p>
  </footer>
</div>
<script src="/assets/js/jr-qrcode.js"></script>
<script>
    let qrString = "<?php echo $data['data']['qr']; ?>";
    let isAlipayQr = "<?php  echo strpos(strtoupper($data['data']['qr']), 'QR.ALIPAY.COM') !== false ? 1 : 0; ?>";
    let expire = "<?php echo $data['order']['expire_time'] ? $data['order']['expire_time'] - time() : 0; ?>";
    let data = {
        token: "<?php echo $data['token']; ?>",
        no: "<?php echo $data['order']['order_no']; ?>",
    }
    let paid = false;
    $(document).ready(function () {
        $(window).on('beforeunload', function () {
            if (!paid) {
                alert("正在提交付款,请不要刷新页面!");
            }
            return false;
        });

        //支付宝模拟点击跳转
        if (isAlipayQr) {
            // $('#qr_link').click()
            // let goPay = '<span id="goPay"> <span>';
            // //给A标签中的文字添加一个能被jQuery捕获的元素
            // $('#qr_link').append(goPay);
            // //模拟点击A标签中的文字
            // $('#goPay').click();
            //window.location = qrString;
            //设置有效期
            expire = 300;
        }

        let expireInterval = null;
        // $('#qrcode').qrcode({width: 300,height: 300,text:qrString});
        let imgBase64 = jrQrcode.getQrBase64(qrString, {width: 300, height: 300});
        $('#qrcode-img').attr('src', imgBase64).show()

        setInterval(function () {
            $.post("/order/check_status.html", data, function (result) {
                if (result.code == 0) {
                    paid = true
                    $('.pay-ok').show();
                    if (expireInterval) clearInterval(expireInterval)
                    $('.pay-btn').removeClass('btn-primary').addClass('btn-success').text('付款已成功');
                    $("#qrcode canvas").css("opacity", "0.02")
                }
            });
        }, 15000)

        /**
         * 二维码过期处理
         */
        function onQrExpired() {
            $('.pay-btn').text("订单已过期,请重新下单!").removeClass('btn-primary').addClass('btn-danger');
            // $("#qrcode canvas").css("opacity","0.02")
            if (expireInterval) clearInterval(expireInterval);
            $('#qrcode-img').attr('src', '/assets/imgs/qr_expired.png');
        }

        if (expire > 0) {
            expireInterval = setInterval(function () {
                if (expire >= 0) {
                    let min = Math.floor(expire / 60)
                    let sec = expire % 60
                    let msg = "请在" + min + "分" + sec + "秒内付款"
                    $('.pay-btn').text(msg)
                    expire--;
                } else {
                    if (expire < 0) expire = 0
                    onQrExpired()
                }

            }, 1000);
        } else if (expire < 0) {
            onQrExpired()
        }
    });
</script>