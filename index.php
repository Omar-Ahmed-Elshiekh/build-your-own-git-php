<?php

// print_r($argv);

$command = $argv[1] ?? null;

$commands = ['init','add','commit','log','hash_object','cat_file'];

if(!$command || !in_array($command,$commands)){
  echo "Error: Usage ./index.php <$command>\n";
  echo "Available commands: init, add, commit, log, hash_object, cat_file\n";
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
  $writeEnable = $args[1] ?? false;

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

function command_cat_file($args){
  $hash = (string)$args[0];

  $dir = '.mygit/objects/' . substr($hash,0,2);
  $file = $dir . '/' . substr($hash,2);

  if(!file_exists($file)){
    echo "Error: Object not found.\n";
    return;
  }

  $compressed = file_get_contents($file);

  $data = gzuncompress($compressed);

  if($data === false){
    echo "Error: Failed to decompress the object.\n";
    return;
  }

  $parts = explode("\0",$data,2);
  // print_r($parts);
  if(count($parts) < 2){
    echo "Error: Invalid MyGit object format.\n";
    return;
  }

  $fileContent = $parts[1];

  echo $fileContent;
}