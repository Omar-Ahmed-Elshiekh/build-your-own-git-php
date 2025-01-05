<?php

// print_r($argv);

$command = $argv[1] ?? null;
$commands = ['init','add','commit','log','hash_object','cat_file','write_tree','ls_tree','ls_files'];

if(!$command || !in_array($command,$commands)){
  echo "Error: Usage ./index.php <$command>\n";
  echo "Available commands: init, add, commit, log, hash_object, cat_file, write_tree, ls_tree\n";
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
  echo "Initialized MyGit repository.\n";
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
    return $hash;
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

function command_write_tree($args,$base = ''){
  $entries = [];
  $dir = '.';
  $items = scandir($dir);

  foreach($items as $item){

    if($item === '.' || $item === '..' || $item === '.mygit' || /*delete it at the end of the project*/ $item === '.git') continue;

    $path = $base ? "$base/$item" : $item;

    if(is_file($path)){
      $hash = command_hash_object([$path,1]);
      $entries[] = ['100644','blob',$hash,$item];
    }else if(is_dir($path)){
      $hash = command_write_tree([],$path); //recursivly do subdirs
      $entries[] = ['040000','tree',$hash,$item];
    }

  }

  $tree_content = '';
  foreach($entries as $entry){
    [$code,$type,$hash,$name] = $entry;
    $tree_content .= "$code $type $hash $name\n";
  }

  $header = "tree " . strlen($tree_content) . "\0";
  $fulldata = $header . $tree_content;

  $hash = sha1($fulldata);
  $compressed = gzcompress($fulldata);

  $dir = '.mygit/objects/' . substr($hash,0,2);
  if(!is_dir($dir)){
    mkdir($dir,0777,true);
  }

  $path = $dir . '/' . substr($hash,2);
  file_put_contents($path,$compressed);

  echo $hash . PHP_EOL;
  return $hash;
}

function command_ls_tree($args){
  $hash = $args[0] ?? null;
  $flag = false;

  if(isset($args[0]) && $args[0] === "--name-only"){
    if (count($args) < 2) {
      echo "Error: Hash required.\n";
      return;
  }
    $flag = true;
    $hash = $args[1];
  }

  $dir = '.mygit/objects/' . substr($hash,0,2);
  $file = $dir . '/' . substr($hash, 2);

  if (!file_exists($file)) {
    echo "Error: Tree object not found.\n";
    return;
}

  $compressed = file_get_contents($file);
  $data = gzuncompress($compressed);

  $parts = explode("\0", $data, 2);
  if (count($parts) < 2) {
      echo "Error: Invalid object format.\n";
      return;
  }

  [$header,$content] = $parts;
  if(!str_starts_with($header,'tree ')){
    echo "Error: Not a tree object.\n";
    return;
  }

  $lines = explode("\n",trim($content));

  foreach($lines as $line){
    if(empty($line)) continue;

    $parts = explode(" ",trim($line),4);
    if(count($parts) !== 4){
      continue;
    }

    [$code ,$type,$hash,$name] = $parts;

    if($flag){
      echo $name . "\n";
    }else{
      echo "$code $type $hash $name\n";
    }

  }

}

//TODO: implemet add command

function command_add($args){
  if(!file_exists(".mygit/index")){
    file_put_contents(".mygit/index",'');
  }

  if (empty($args)) {
    echo "Error: No paths specified for adding\n";
    return;
  }

  $dir = '.';
  $index_entries = [];
  $index_contents = file_get_contents('.mygit/index');
  if($index_contents){
    $index_entries = array_filter(explode("\n",$index_contents));
  }

  $index = [];
  
  foreach($index_entries as $entry){
    $parts = explode(" ",$entry);
    if(count($parts) >= 4){
      $path = $parts[3];
      $index[$path] = $entry;
    }
  }

  foreach($args as $path){
    if(!file_exists($path)){
      echo "Error: '$path' does not exist\n";
      continue;
    }

    if(is_file($path)){
      add_file($path,$index);
    }else if(is_dir($path)){
      add_dir($path,$index);
    }
  }

  ksort($index);
  $new_content = implode("\n",$index);
  if($new_content){
    $new_content .= "\n";
  }

  file_put_contents(".mygit/index",$new_content);

}

function add_file($path,&$index){
  if(str_starts_with($path,'./mygit')) return;
  $hash = command_hash_object([$path,1]);
  $mode = '100644';
  $type = 'blob';
  $entry = "$mode $type $hash $path";
  $index[$path] = $entry;
}

function add_dir($dir,&$index){
  $items = scandir($dir);

  foreach($items as $item){
    if($item === '.' || $item === '..' || $item === '.mygit' || /*delete it at the end of the project*/ $item === '.git') {
      continue;
  }

  $path = $dir === '.' ? $item : "$dir/$item";

  if(is_file($path)){
    add_file($path,$index);
  }else if(is_dir($path)){
    add_dir($path,$index);
  }
  }
}