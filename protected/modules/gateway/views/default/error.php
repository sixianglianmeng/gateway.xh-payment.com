<div class="error-wall load-error">
        <div class="error-container">
            <h1>请求失败</h1>
            <h3>错误信息：<?php echo $message.'(code:'.$code.')'; ?></h3>
        </div>
    </div>

<script>
  $(document).ready(function(){

  });
</script>
<style>
    body {
        padding: 0;
        margin: 0;
        font-family: "Oxygen", sans-serif;
    }

    .error-wall {
        width: 100%;
        height: 100%;
        position: fixed;
        text-align: center;
    }
    .error-wall.load-error {
        background-color: #fcf8e3;
    }
    .error-wall.matinence {
        background-color: #a473b1;
    }
    .error-wall.missing-page {
        background-color: #00bbc6;
    }
    .error-wall .error-container {
        display: block;
        width: 100%;
        position: absolute;
        left: 50%;
        top: 30%;
        transform: translate(-50%, -50%);
        -webkit-transform: translate(-50%, -50%);
        -moz-transform: translate(-50%, -50%);
    }
    .error-wall .error-container h1 {
        color: #464444;
        font-size: 80px;
        margin: 0;
    }
    @media (max-width: 850px) {
        .error-wall .error-container h1 {
            font-size: 65px;
        }
    }
    .error-wall .error-container h3 {
        color: #466666;
        font-size: 34px;
        margin: 0;
        margin-top: 40px;
    }
    @media (max-width: 850px) {
        .error-wall .error-container h3 {
            font-size: 25px;
        }
    }
    .error-wall .error-container h4 {
        margin: 0;
        color: #fff;
        font-size: 40px;
    }
    @media (max-width: 850px) {
        .error-wall .error-container h4 {
            font-size: 35px;
        }
    }
    .error-wall .error-container p {
        font-size: 15px;
    }
    .error-wall .error-container p:first-of-type {
        color: #464444;
        font-weight: lighter;
    }
    .error-wall .error-container p:nth-of-type(2) {
        color: #464444;
        font-weight: bold;
    }
    .error-wall .error-container p.type-white {
        color: #fff;
    }
    @media (max-width: 850px) {
        .error-wall .error-container p {
            font-size: 12px;
        }
    }
    @media (max-width: 390px) {
        .error-wall .error-container p {
            font-size: 10px;
        }
    }

</style>