<?php
namespace app\modules\gateway\tests\functional;


class ValidatorTest extends \PHPUnit_Framework_TestCase
{
    public function testBankNoValidate()
    {
        $cs = ['6217003810042591522'];
        foreach ($cs as $c){
            $valid = Util::validate($c,Macro::CONST_PARAM_TYPE_BANK_NO);

            $this->assertNotEquals(true, $valid);
        }

    }
}
