<?php

/**
 * Created by PhpStorm.
 * User: terry
 * Date: 2016/11/20
 * Time: 13:19
 * NOTE: 64bit PHP required, and Key must be length % 4 == 0
 */
class KeyObfuscation
{
    const MASTER_KEY = "12345678901234567890123456789012";

    const MASTER_OBFUSCATED_KEY = "05d1b469a2ee076761743efadf2d8cc5c08c87c67548b59cae548e23201a786f160bca18d026c7f772e29fcbfcdd8fd4ec30f0a29ab1535c1140c12262089bfa";

    static $xorMask = [
        0x11, 0x55, 0x99, 0xdd, 0xfd,
        0x22, 0x66, 0xaa, 0xee, 0xfc,
        0x33, 0x77, 0xbb, 0xff, 0xfb,
        0x44, 0x88, 0xcc, 0xfe
    ];

    const MOD_PX = 15487399; // big prime
    const MOD_PM = 1721000000; // any big number coprime with px
    const MOD_PY = 406752599; //

    // Helpers
    static function read_uint32($data, $offset)
    {
        $a = $data[$offset];
        $b = $data[$offset + 1];
        $c = $data[$offset + 2];
        $d = $data[$offset + 3];

        return $a | ($b << 8) | ($c << 16) | ($d << 24);
    }

    static function read_uint16($data, $offset)
    {
        $a = $data[$offset];
        $b = $data[$offset + 1];

        // var_export($data);

        return $b << 8 | $a;
    }

    // Input key limit: length % 4 == 0
    public static function ObfuscateKey($key)
    {
        $data = unpack("C*", $key);
        $data = array_values($data);
        $length = count($data);

        // subset parity
        for ($i = 0; $i < $length; $i += 4) {
            $x = KeyObfuscation::read_uint32($data, $i);
            $t = ($x ^ ($x >> 1)) & 0x44444444;
            $u = ($x ^ ($x << 2)) & 0xcccccccc;

            $y = (($x & 0x88888888) >> 3) | ($t >> 1) | $u;

            $data[$i] = $y & 0xff;
            $data[$i + 1] = ($y >> 8) & 0xff;
            $data[$i + 2] = ($y >> 16) & 0xff;
            $data[$i + 3] = ($y >> 24) & 0xff;
        }

        // gcd mod inverse magic
        $o = [];
        for ($i = 0; $i < $length; $i += 2) {
            $x = KeyObfuscation::read_uint16($data, $i);
            $y = $x * KeyObfuscation::MOD_PX % KeyObfuscation::MOD_PM;

            $o[] = $y & 0xff;
            $o[] = ($y >> 8) & 0xff;
            $o[] = ($y >> 16) & 0xff;
            $o[] = ($y >> 24) & 0xff;
        }

        // output length == len(data) * 2

        // xor with mask
        $olen = $length * 2;
        $idx = 3;
        for ($i = 0; $i < $olen; $i += 4) {
            $o[$i] = $o[$i] ^ KeyObfuscation::$xorMask[$idx % 19];
            $idx++;
            $o[$i + 1] = $o[$i + 1] ^ KeyObfuscation::$xorMask[$idx % 19];
            $idx++;
            $o[$i + 2] = $o[$i + 2] ^ KeyObfuscation::$xorMask[$idx % 19];
            $idx++;
            $o[$i + 3] = $o[$i + 3] ^ KeyObfuscation::$xorMask[$idx % 19];
            $idx++;

            $x = KeyObfuscation::read_uint32($o, $i);
            $t = ($x ^ ($x >> 7)) & 0x00550055;
            $u = $x ^ $t ^ ($t << 7);
            $t = ($u ^ ($u >> 14)) & 0x0000cccc;

            $y = $u ^ $t ^ ($t << 14);

            $o[$i] = $y & 0xff;
            $o[$i + 1] = ($y >> 8) & 0xff;
            $o[$i + 2] = ($y >> 16) & 0xff;
            $o[$i + 3] = ($y >> 24) & 0xff;
        }

        return bin2hex(implode(array_map("chr", $o)));
    }

    /* restore will not use in production */

    public static function RestoreFromObfuscatedString($hex_ob)
    {
        $bin_ob = hex2bin($hex_ob);
        $bin_ob_bytes = unpack("C*", $bin_ob);
        return KeyObfuscation::try_restore_obfuscate(array_values($bin_ob_bytes));
    }


    // Helpers

    static function subset_parity_restore($y)
    {
        $t = (($y & 0x11111111) << 3) | ((($y & 0x11111111) << 2) ^ (($y & 0x22222222) << 1));

        return $t | (($t >> 2) ^ (($y >> 2) & 0x33333333));
    }

    static function ob_xor($data, $m1, $m2, $m3, $m4, $m5,
                    $m6, $m7, $m8, $m9, $m10,
                    $m11, $m12, $m13, $m14, $m15,
                    $m16, $m17, $m18, $m19
    )
    {
        $o = [];
        $m = [$m1, $m2, $m3, $m4, $m5,
            $m6, $m7, $m8, $m9, $m10,
            $m11, $m12, $m13, $m14, $m15,
            $m16, $m17, $m18, $m19];
        $i = 3;

        foreach ($data as $d) {
            $o[] = $d ^ $m[$i % 19];
            $i++;
        }

        return $o;
    }

    static function shuffle_bits_restore($y)
    {
        $mask1 = 0x00550055;
        $d1 = 7;
        $mask2 = 0x0000cccc;
        $d2 = 14;

        $t = ($y ^ ($y >> $d2)) & $mask2;
        $u = $y ^ $t ^ ($t << $d2);
        $t = ($u ^ ($u >> $d1)) & $mask1;

        return $u ^ $t ^ ($t << $d1);
    }

    static function rs_numb_base($data)
    {
        $o = [];

        for ($i = 0; $i < count($data); $i += 4) {
            $ek = KeyObfuscation::read_uint32($data, $i);
            $k = $ek * KeyObfuscation::MOD_PY % KeyObfuscation::MOD_PM;

            $o[] = $k & 0xff;
            $o[] = ($k >> 8) & 0xff;
        }

        return $o;
    }

    static function try_subset_parity_restore($data)
    {
        $o = [];
        for ($i = 0; $i < count($data); $i += 4) {
            $k = KeyObfuscation::read_uint32($data, $i);
            $ek = KeyObfuscation::subset_parity_restore($k);

            $o[] = $ek & 0xff;
            $o[] = ($ek >> 8) & 0xff;
            $o[] = ($ek >> 16) & 0xff;
            $o[] = ($ek >> 24) & 0xff;
        }

        return $o;
    }

    static function try_shuffle_bits_restore($data)
    {
        $o = [];
        for ($i = 0; $i < count($data); $i += 4) {
            $k = KeyObfuscation::read_uint32($data, $i);
            $ek = KeyObfuscation::shuffle_bits_restore($k);

            $o[] = $ek & 0xff;
            $o[] = ($ek >> 8) & 0xff;
            $o[] = ($ek >> 16) & 0xff;
            $o[] = ($ek >> 24) & 0xff;
        }

        return $o;
    }

    static function try_restore_obfuscate($data)
    {
        $dec = KeyObfuscation::try_shuffle_bits_restore($data);
        $dec = KeyObfuscation::ob_xor($dec,
            0x11, 0x55, 0x99, 0xdd, 0xfd,
            0x22, 0x66, 0xaa, 0xee, 0xfc,
            0x33, 0x77, 0xbb, 0xff, 0xfb,
            0x44, 0x88, 0xcc, 0xfe
        );

        $dec = KeyObfuscation::rs_numb_base($dec);
        $dec = KeyObfuscation::try_subset_parity_restore($dec);

        return implode(array_map("chr", $dec));
    }

}

// Test
$ob = KeyObfuscation::ObfuscateKey(KeyObfuscation::MASTER_KEY);
print "OB Result: " . PHP_EOL . "$ob " . PHP_EOL . " == " . PHP_EOL . KeyObfuscation::MASTER_OBFUSCATED_KEY . PHP_EOL;

$key = KeyObfuscation::RestoreFromObfuscatedString($ob);

print "----- " . PHP_EOL;

print "Restore: " . PHP_EOL . "$key " . PHP_EOL . " == " . PHP_EOL . KeyObfuscation::MASTER_KEY . PHP_EOL;

