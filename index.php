<?php

print_r($argv);

$command = $argv[1] ?? null;

$commands = ['init','add','commit','log','hash_object'];

if(!$command || !in_array($command,$commands)){
  echo "Usage ./index.php <$command>\n";
  echo "Available commands: init, add, commit, log\n";
  exit(1);
}

call_user_func("command_$command",array_slice($argv,2));

function command_init(){
  if(is_dir('.mygit')){
    echo "Repository already initialized.\n";
    return;
  }

  mkdir('.mygit');
  mkdir('.mygit/objects');
  mkdir('.mygit/refs');
  file_put_contents('.mygit/HEAD','ref: refs/heads/main\n');
  echo "Initialized Git-like repository.\n";
}

function command_hash_object($args){

  $filePath = $args[0];
  $writeEnable = $args[1];

  if(!file_exists($filePath)){
    echo "Error: File not found.\n";
    return;
  }

  $fileContents = file_get_contents($filePath);

  $header = "blob " . $fileContents . "\0";

  $fullContent = $header . $fileContents;

  $hash = sha1($fullContent);

  if($writeEnable){
    $compressed = gzcompress($fullContent);

    $dir = '.mygit/objects/' . substr($hash,0,2);
    if(!is_dir($dir)){
      mkdir($dir,0777,true);
    } 

    $filePath = $dir . '/' . substr($hash,2);
    file_put_contents($filePath,$compressed);
  }
    echo $hash . PHP_EOL;
}