<?php
use yii\helpers\Html;
?>
<?php $this->beginPage() ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8"/>
        <?= Html::csrfMetaTags() ?>
        <title><?= Html::encode($this->title) ?></title>
        <?php $this->head() ?>
        <?php if (isset($this->blocks['css'])){ ?>
            <?= $this->blocks['css'] ?>
        <?php } ?>
    </head>
    <body>
    <?php $this->beginBody() ?>
    <?= $content ?>
    <script>
    </script>
    <?php $this->endBody() ?>
    </body>
    </html>
<?php $this->endPage() ?>