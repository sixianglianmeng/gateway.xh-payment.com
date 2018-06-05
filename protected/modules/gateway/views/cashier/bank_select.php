<?php $this->beginBlock('css'); ?>
<!--<link type="text/css" rel="stylesheet" href="/assets/css/simple-radius-border.css"/>-->
<!--<link type="text/css" rel="stylesheet" href="/assets/css/radio-banks.css"/>-->
<?php $this->endBlock(); ?>
<div class="container">
    <form id="mainForm" class="form-horizontal" action="" method="POST" onsubmit="return submitCheck();">
        <div class="container-fluid">
            <div class="row-fluid">
                <div class="span10 offset1">
                    <span class="d-block p-2 bg-primary text-white">订单信息</span>
                    <div class="low-half-radius-border" style="padding-top: 10px;">
                        <ul class="list-group mb-3">
                            <li class="list-group-item d-flex justify-content-between lh-condensed">
                                <div>
                                    <h6 class="my-0">订单号</h6>
                                </div>
                                <span class="text-muted"><span><?php echo $data['order']['order_no']; ?></span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between lh-condensed">
                                <div>
                                    <h6 class="my-0">金额</h6>
                                </div>
                                <span class="text-muted">￥<span><?php echo $data['order']['amount']; ?>元</span>
                            </li>
                        </ul>
                </div>
            </div>
            <div class="row-fluid" style="margin-top: 10px">
                <div class="span10 offset1">
                    <ul class="nav nav-tabs" role="tablist">
                        <li class="nav-item">
                            <a class="nav-link active" href="#bank" role="tab" data-toggle="tab">请选择银行</a>
                        </li>
                    </ul>
                    <div class="tab-content" style="display:flex;border-left: 1px solid #ddd; border-right: 1px solid #ddd; border-bottom: 1px solid #ddd;
                    margin-top:
                    -20px;">
                        <div class="container-fluid" style="padding-top:20px;" id="bank">
                       <?php
                       foreach ($data['banks'] as $b){
                       ?>
                        <div class="col-2 bank-item">
                            <label class="radio">
                                <input type="radio" name="bankCode" class="radio-img-bank" value="<?php echo $b['platform_bank_code']; ?>">
                                <div class="bank-img-wrap">
                                <img src="/assets/imgs/banks/<?php echo strtolower($b['platform_bank_code']); ?>.gif" title="<?php echo $b['bank_name']; ?>" alt="<?php echo $b['bank_name']; ?>" />
                                </div>
                            </label>
                        </div>
                    <?php } ?>

                        </div>
                    </div>
                </div>
            </div>
        </div>
        <input type="hidden" name="token" value="<?php echo $data['token']; ?>" />
        <input type="hidden" name="order_no" value="<?php echo $data['order']['order_no']; ?>" />
            <div id="form-alert" class="alert alert-danger" role="alert" style="margin-top: 20px;display: none;">

            </div>
            <div class="row-fluid" style="margin-top: 20px;width: 100%">
                <button class="btn btn-primary  btn-block pay-btn " type="submit" style="width: 40%; margin-left: 30%">确认付款</button>
            </div>

    </form>
    <footer class="my-5 pt-5 text-muted text-center text-small">
        <p class="mb-1">&copy; <?php echo date('Y') ?> 版权所有</p>
    </footer>
</div>
<script>
  function submitCheck(){
    let bankCode = $("input[name='bankCode']:checked").val();
    if(typeof bankCode == 'undefined' || !bankCode){
      $('#form-alert').html('请选择银行').show().fadeOut(5000)
      return false
    }

    return true
  }
  $(document).ready(function(){

      // $.post("/order/check_status.html",data,function(result){
      //   console.log(result);
      //   if(result.code == 0){
      //       // $('.pay-ok').show();
      //       $('.pay-btn').removeClass('btn-primary').addClass('btn-success').text('付款已成功');
      //   }
      // });
  });
</script>
<style>
    .bank-img-wrap{
        display: inline-block;
        margin-left: 5px;
    }
    .bank-item{
        float: left;
        margin: 10px;
    }
    .low-half-radius-border
    {
        border: 1px solid #ddd;
        -webkit-border-radius: 5px;
        -moz-border-radius: 5px;
        border-radius: 5px;
        background-color: #F7F8F9;
        margin-bottom: 1px;
    }
</style>