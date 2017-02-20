<?php

require "../src/MyMemcacheClient.php";

try {
    $memcache = new MyMemcacheClient();
    if (false === $memcache->connect()) {
        throw new Exception("connect to memcached failed: " . $memcache->getlastError());
    }

    $memcache->flushAll();

    echo "a=", $memcache->get("a"), PHP_EOL;

    if (false === $memcache->set("a", "This is a")) {
        echo "set a failed: ", $memcache->getLastError(), PHP_EOL;
    } else {
        echo "a: ", $memcache->get("a"), PHP_EOL;
    }

    $memcache->set('total', 10, 30);
    echo "total: ", $memcache->get("total"), PHP_EOL;
    $memcache->incr("total", 2);
    echo "total: ", $memcache->get("total"), PHP_EOL;
    $memcache->decr("total", 1);
    echo "total: ", $memcache->get("total"), PHP_EOL;

    if (false === $memcache->delete("total")) {
        echo "delete total failed", PHP_EOL;
    } else {
        echo "delete total success! total: ", $memcache->get("total"), PHP_EOL;
    }

    $stats = $memcache->stats();
    foreach($stats as $k => $v) {
        echo $k,": ", $v, PHP_EOL;
    }

    $memcache->append("a", ", appended consdfsdftent");
    echo $memcache->get("a"), PHP_EOL;

    $memcache->prepend("a", "Prepended condfdsftent, ");
    echo $memcache->get("a"), PHP_EOL;

    $memcache->set("b", "This is b");

    $memcache->set("c", "This is c");

    //$arr = $memcache->get(['a', 'b', 'c']);
    //print_r($arr);

    $result = $memcache->cas('a', "dfddsfgddsfsfd");

    echo $result, PHP_EOL;
    echo $memcache->getLastError();
    $memcache->close();

} catch (Exception $e) {
    echo $e->getMessage();
}