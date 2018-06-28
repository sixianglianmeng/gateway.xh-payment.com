#!/bin/bash

APPLICATION_ENV='dev'
MODULE_NAME='gateway'
IDC_NUM=1
IDC_ID=0

export APPLICATION_ENV MODULE_NAME IDC_NUM IDC_ID
phpunit --colors --coverage-html "$MODULE_NAME" --testsuite "$MODULE_NAME" "$1"