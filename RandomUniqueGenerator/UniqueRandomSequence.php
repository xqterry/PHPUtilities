<?php
/**
 * Created by PhpStorm.
 * User: terry
 * Date: 2016/12/14
 * Time: 16:23
 */

// 模4余3的质数 大于输出总数 32^6 , 小于 2^32
$big_prime = 1073741827;
$code_base = "YZ57V84GB3FQMWSKDL6JTE29CXPARUNH"; // shuffled
$seed = 0x00001357;
$mask = 0x9527;

function permuteURN($x)
{
    global $big_prime;
    global $mask;
    global $seed;

//    $x ^= 0x35351414;

    $x += $seed;
    $x %= $big_prime;

    if($x >= $big_prime)
    {
        // out of range ?
        return $x;
    }

    $residue = ($x * $x) % $big_prime;

    $y = $x <= $big_prime / 2 ? $residue : $big_prime - $residue;

    return ($y ^ $mask) % $big_prime;
}

function code_from_seq($seq)
{
    global $code_base;

    $a = $seq;
    $m = 32;

    $ret = "";

    do {
        $b = $a % $m;
        $a = (int) ($a / 32);

        $ret = $code_base[$b] . $ret;

    } while($a > 0);

    for($i = 6 - strlen($ret); $i > 0; $i--)
        $ret = $code_base[0] . $ret;

    return $ret;
}

ini_set("memory_limit", "-1");

// test ?

for($i = 0; $i < 100; $i++)
{
    //print "$i:" . permuteURN($i) . PHP_EOL;
}

for($i = 0; $i < 1000; $i++)
{
    print "$i:" . permuteURN($i) . " " . code_from_seq(permuteURN($i)) . PHP_EOL;
}
