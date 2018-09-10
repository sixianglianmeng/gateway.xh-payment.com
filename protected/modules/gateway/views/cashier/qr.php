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
            </ul>
        </div>
        <div class="col-md-4">
            <h4 class="mb-3"><?php echo $data['order']['pay_method_str']; ?>付款</h4>
            <form class="needs-validation" novalidate>
                <div class="row" style="text-align: center">
<!--                  <img style="width:300px;height:300px;" src="http://qr.liantu.com/api.php?text=--><?php //echo urlencode($data['data']['qr']); ?><!--"/>-->
                    <div id="qrcode"></div>
                </div>
            </form>
        </div>
        <div class="col-md-2"></div>
    </div>

    <footer class="my-5 pt-5 text-muted text-center text-small">
        <p class="mb-1">&copy; <?php echo date('Y') ?> 版权所有</p>
    </footer>
</div>
<script>
  let qrString = "<?php echo $data['data']['qr']; ?>";
  let expire = "<?php echo $data['order']['expire_time']?$data['order']['expire_time']-time():0; ?>";
  let data = {
    token: "<?php echo $data['token']; ?>",
    no: "<?php echo $data['order']['order_no']; ?>",
  }
  $(document).ready(function(){
    let expireInterval = null;
    $('#qrcode').qrcode({width: 300,height: 300,text:qrString});

    setInterval(function () {
      $.post("/order/check_status.html",data,function(result){
        if(result.code == 0){
            $('.pay-ok').show();
            if(expireInterval) clearInterval(expireInterval)
            $('.pay-btn').removeClass('btn-primary').addClass('btn-success').text('付款已成功');
            $("#qrcode canvas").css("opacity","0.02")
        }
      });
    },15000)

    if(expire>0){
      expireInterval = setInterval(function () {
        if(expire>=0){
          let min = Math.floor(expire/60)
          let sec = expire%60
          let msg = "请在"+min+"分"+sec+"秒内付款"
          $('.pay-btn').text(msg)
          expire--;
        }else{
          if(expire<0) expire =0
          $('.pay-btn').text("订单已过期,请重新下单!").removeClass('btn-primary').addClass('btn-danger')
          $("#qrcode canvas").css("opacity","0.02")
          clearInterval(expireInterval)
        }

      },1000);
    }else if(expire<0){
      $('.pay-btn').text("订单已过期,请重新下单!").removeClass('btn-primary').addClass('btn-danger')
      $("#qrcode canvas").css("opacity","0.02")
    }
  });
</script>