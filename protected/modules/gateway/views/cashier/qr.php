<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <link rel="stylesheet" href="/assets/css/bootstrap.min.css" >
    <title>订单付款</title>
</head>
<body>
<body class="bg-light">

<div class="container">
    <div class="py-5 text-center">
        <h2>订单付款</h2>
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
                <li class="list-group-item d-flex justify-content-between lh-condensed">
                    <button class="btn btn-primary btn-lg btn-block" type="submit">已付款成功</button>
                </li>
            </ul>
        </div>
        <div class="col-md-4">
            <h4 class="mb-3"><?php echo $data['order']['pay_method_str']; ?>付款</h4>
            <form class="needs-validation" novalidate>
                <div class="row" style="text-align: center">
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
</body>
<script src="/assets/js/jquery.min.js"></script>
<script src="/assets/js/bootstrap.min.js"></script>
<script src="/assets/js/jquery.qrcode.min.js"></script>
<script>
    let qrString = "<?php echo $data['data']['qr']; ?>";
  $(document).ready(function(){
    $('#qrcode').qrcode({width: 300,height: 300,text:qrString});
  });
</script>
</html>