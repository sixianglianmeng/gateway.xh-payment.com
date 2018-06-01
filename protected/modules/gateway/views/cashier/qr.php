<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <!-- import CSS -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/element-ui/2.4.0/theme-chalk/index.css">
</head>
<body>
<div id="app">

</div>
</body>
<!-- import Vue before Element -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/vue/2.5.16/vue.min.js"></script>
<!-- import JavaScript -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/element-ui/2.4.0/index.js"></script>
<script>
  new Vue({
    el: '#app',
    data: function() {
      return { visible: false }
    }
  })
</script>
</html>