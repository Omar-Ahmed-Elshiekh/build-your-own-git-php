<?php

$command = $argv[1] ?? null;
$commands = ['init', 'add', 'commit', 'log', 'hash_object', 'cat_file', 'write_tree', 'ls_tree', 'ls_files'];

if (!$command || !in_array($command, $commands)) {
  echo "Error: Usage ./index.php <$command>\n";
  echo "Available commands: init, add, commit, log, hash_object, cat_file, write_tree, ls_tree\n";
  exit(1);
}

call_user_func("command_$command", array_slice($argv, 2));

function command_init()
{
  if (is_dir('.mygit')) {
    echo "Repository already initialized.\n";
    return;
  }

  mkdir('.mygit');
  mkdir('.mygit/objects');
  mkdir('.mygit/refs');
  file_put_contents('.mygit/HEAD', "ref: refs/heads/main\n");
  echo "Initialized MyGit repository.\n";
}

function command_hash_object($args)
{

  $filePath = $args[0];
  $writeEnable = $args[1] ?? false;

  if (!file_exists($filePath)) {
    echo "Error: File not found.\n";
    return;
  }

  $fileContents = file_get_contents($filePath);

  $header = "blob " . $fileContents . "\0";

  $fullContent = $header . $fileContents;

  $hash = sha1($fullContent);

  if ($writeEnable) {
    $compressed = gzcompress($fullContent);

    $dir = '.mygit/objects/' . substr($hash, 0, 2);
    if (!is_dir($dir)) {
      mkdir($dir, 0777, true);
    }

    $filePath = $dir . '/' . substr($hash, 2);
    file_put_contents($filePath, $compressed);
  }
  echo $hash . PHP_EOL;
  return $hash;
}

function command_cat_file($args)
{
  $hash = (string) $args[0];

  $dir = '.mygit/objects/' . substr($hash, 0, 2);
  $file = $dir . '/' . substr($hash, 2);

  if (!file_exists($file)) {
    echo "Error: Object not found.\n";
    return;
  }

  $compressed = file_get_contents($file);

  $data = gzuncompress($compressed);

  if ($data === false) {
    echo "Error: Failed to decompress the object.\n";
    return;
  }

  $parts = explode("\0", $data, 2);
  if (count($parts) < 2) {
    echo "Error: Invalid MyGit object format.\n";
    return;
  }

  $fileContent = $parts[1];

  echo $fileContent;
}

function command_write_tree($args, $base = '')
{
  $entries = [];
  $dir = $args[0] ?? '.';
  $scan_path = $base ? $base : $dir;
  $items = scandir($scan_path);

  foreach ($items as $item) {

    if ($item === '.' || $item === '..' || $item === '.mygit' || /*delete it at the end of the project*/ $item === '.git')
      continue;

    $full_path = $scan_path . '/' . $item;
    if (is_file($full_path)) {
      $hash = command_hash_object([$full_path, 1]);
      $entries[] = ['100644', 'blob', $hash, $item];
    } else if (is_dir($full_path)) {
      $hash = command_write_tree([$full_path], $full_path); //recursivly do subdirs
      $entries[] = ['040000', 'tree', $hash, $item];
    }

  }

  $tree_content = '';
  foreach ($entries as $entry) {
    [$mode, $type, $hash, $name] = $entry;
    $tree_content .= "$mode $type $hash $name\n";
  }

  $header = "tree " . strlen($tree_content) . "\0";
  $fulldata = $header . $tree_content;

  $hash = sha1($fulldata);
  $compressed = gzcompress($fulldata);

  $dir = '.mygit/objects/' . substr($hash, 0, 2);
  if (!is_dir($dir)) {
    mkdir($dir, 0777, true);
  }

  $path = $dir . '/' . substr($hash, 2);
  file_put_contents($path, $compressed);

  echo $hash . PHP_EOL;
  return $hash;
}
function write_tree_from_index()
{
  if (!file_exists('.mygit/index')) {
    echo "Error: No index file found\n";
    return null;
  }

  $entries = [];
  $index_content = file_get_contents('.mygit/index');
  $lines = explode("\n", trim($index_content));
  foreach ($lines as $line) {
    if (empty(trim($line)))
      continue;

    $parts = explode(" ", trim($line));
    if (count($parts) < 4)
      continue;

    $entries[] = $parts;
  }

  $tree_content = '';
  foreach ($entries as $entry) {
    $tree_content .= implode(" ", $entry) . "\n";
  }

  $header = "tree " . strlen($tree_content) . "\0";
  $full_content = $header . $tree_content;

  $hash = sha1($full_content);
  $compressed = gzcompress($full_content);

  $dir = ".mygit/objects/" . substr($hash, 0, 2);
  if (!is_dir($dir)) {
    mkdir($dir, 0777, true);
  }

  $path = $dir . "/" . substr($hash, 2);
  file_put_contents($path, $compressed);

  return $hash;
}
function command_ls_tree($args)
{
  $hash = $args[0] ?? null;
  $flag = false;

  if (isset($args[0]) && $args[0] === "--name-only") {
    if (count($args) < 2) {
      echo "Error: Hash required.\n";
      return;
    }
    $flag = true;
    $hash = $args[1];
  }

  $dir = '.mygit/objects/' . substr($hash, 0, 2);
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

  [$header, $content] = $parts;
  if (!str_starts_with($header, 'tree ')) {
    echo "Error: Not a tree object.\n";
    return;
  }

  $lines = explode("\n", trim($content));

  foreach ($lines as $line) {
    if (empty($line))
      continue;

    $parts = explode(" ", trim($line), 4);
    if (count($parts) !== 4) {
      continue;
    }

    [$mode, $type, $hash, $name] = $parts;

    if ($flag) {
      echo $name . "\n";
    } else {
      echo "$mode $type $hash $name\n";
    }

  }

}

function command_add($args)
{
  if (!file_exists(".mygit/index")) {
    file_put_contents(".mygit/index", '');
  }

  if (empty($args)) {
    echo "Error: No paths specified for adding\n";
    return;
  }

  $dir = '.';
  $index_entries = [];
  $index_contents = file_get_contents('.mygit/index');
  if ($index_contents) {
    $index_entries = array_filter(explode("\n", $index_contents));
  }

  $index = [];

  foreach ($index_entries as $entry) {
    $parts = explode(" ", $entry);
    if (count($parts) >= 4) {
      $path = $parts[3];
      $index[$path] = $entry;
    }
  }

  foreach ($args as $path) {
    if (!file_exists($path)) {
      echo "Error: '$path' does not exist\n";
      continue;
    }

    if (is_file($path)) {
      add_file($path, $index);
    } else if (is_dir($path)) {
      add_dir($path, $index);
    }
  }

  ksort($index);
  $new_content = implode("\n", $index);
  if ($new_content) {
    $new_content .= "\n";
  }

  file_put_contents(".mygit/index", $new_content);

}

function add_file($path, &$index)
{
  if (str_starts_with($path, './mygit'))
    return;
  $hash = command_hash_object([$path, 1]);
  $mode = '100644';
  $type = 'blob';
  $entry = "$mode $type $hash $path";
  $index[$path] = $entry;
}

function add_dir($dir, &$index)
{
  $items = scandir($dir);

  foreach ($items as $item) {
    if ($item === '.' || $item === '..' || $item === '.mygit' || /*delete it at the end of the project*/ $item === '.git') {
      continue;
    }

    $path = $dir === '.' ? $item : "$dir/$item";

    if (is_file($path)) {
      add_file($path, $index);
    } else if (is_dir($path)) {
      add_dir($path, $index);
    }
  }
}

function command_ls_files($args)
{
  $flag = $args[0] ?? null;
  $index_path = './.mygit/index';

  if (!file_exists($index_path)) {
    echo "Error: Index file not found. Have you added any files?\n";
    return;
  }

  $index_content = file_get_contents($index_path);

  if (empty($index_content)) {
    return;
  }

  $index_entries = explode("\n", $index_content);
  foreach ($index_entries as $entry) {
    $path = explode(" ", trim($entry), 4)[3];
    if ($flag === "--stage" || $flag === "-s") {
      echo $entry . "\n";
    } else if ($flag === null) {
      echo $path . "\n";
    } else {
      echo "Error: Undefined flag " . $flag . "\n";
      return;
    }
  }
}

function command_commit($args)
{
  if (!isset($args[0]) || $args[0] !== '-m' || !isset($args[1])) {
    echo "Error: Please provide a commit message using -m flag\n";
    return;
  }

  $msg = $args[1];

  $tree_hash = write_tree_from_index();
  if (!$tree_hash) {
    echo "Error: Nothing to commit\n";
    return;
  }

  $parent_hash = null;
  if (file_exists(".mygit/HEAD")) {
    $head_content = trim(file_get_contents(".mygit/HEAD"));
    if (file_exists(".mygit/" . $head_content)) {
      $parent_hash = trim(file_get_contents(".mygit/" . $head_content));
    }
  }

  $author = "John Doe <john@example.com>";
  $timestamp = time();

  $commit_content = "tree " . $tree_hash . "\n";
  if ($parent_hash) {
    $commit_content .= "parent " . $parent_hash . "\n";
  }
  $commit_content .= "author " . $author . " " . $timestamp . "\n";
  $commit_content .= "committer " . $author . " " . $timestamp . "\n";
  $commit_content .= "message " . $msg . "\n";

  $header = "commit " . strlen($commit_content) . "\0";
  $full_content = $header . $commit_content;

  $hash = sha1($full_content);
  $compressed = gzcompress($full_content);

  $dir = ".mygit/objects/" . substr($hash, 0, 2);
  if (!is_dir($dir)) {
    mkdir($dir, 0777, true);
  }

  $path = $dir . "/" . substr($hash, 2);
  file_put_contents($path, $compressed);

  if (!is_dir(".mygit/refs/heads")) {
    mkdir(".mygit/refs/heads", 0777, true);
  }

  file_put_contents('.mygit/refs/heads/main', $hash);

  echo "Created commit " . $hash . "\n";
}