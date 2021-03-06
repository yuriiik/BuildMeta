<?php

namespace BuildMeta;

use BuildMeta\Exception\{ParseException, ArgumentException};
use CFPropertyList\CFPropertyList;
use ApkParser\Parser as ApkParser;
use IosPngParser\Parser as CgBIParser;

/**
 * 
 */
class BuildMeta
{  
  public $buildType;
  public $iconOutputPath;
  public $tmpFolderPath;

  public $name;
  public $version;
  public $build;
  public $bundleId;
  public $minOS;  
  
  function __construct(string $buildPath) {
    $this->buildPath = $buildPath;
  }

  function parse() {
    $this->validateArguments();
    try {
      switch ($this->buildExt()) {
        case BuildMeta::IPA_EXT:
          $this->parseIpa();
          break;        
        case BuildMeta::APK_EXT:
          $this->parseApk();
          break;        
        default:
          break;
      }
    } catch (\Error $e) {
      throw $e;
    } finally {
      $this->cleanup();
    }
  }

  private const IPA_DEFAULT_ICON_NAME = 'AppIcon';
  private const IPA_EXT = 'ipa';
  private const APK_EXT = 'apk';

  private $buildPath;
  private $outputFolderPath;
  private $iconName;

  private function parseIpa() {
    $appFolderPath = $this->unzipIpa();
    $this->parseInfoPlist($appFolderPath);
    $this->parseAppIcon($appFolderPath);
  }

  private function parseApk() {
    try {
      $apk = new ApkParser($this->buildPath);
      $manifest = $apk->getManifest();
      $application = $manifest->getApplication();

      $this->name = $apk->getResources($application->getLabel())[0] ?? null;
      $this->version = $manifest->getVersionName();
      $this->build = $manifest->getVersionCode();
      $this->bundleId = $manifest->getPackageName();
      $this->minOS = $manifest->getMinSdk()->platform;

      $appIconResourceKey = $apk->getManifest()->getApplication()->getIcon();
      $appIconResource = $apk->getResources($appIconResourceKey)[0] ?? null;
      if (isset($appIconResource)) {
        $imgString = stream_get_contents($apk->getStream($appIconResource));
        $img = imagecreatefromstring($imgString);
        imagesavealpha($img, true);
        imagepng($img, $this->iconOutputPath);
        imagedestroy($img);
      }
    } catch (\Exception $e) {
      throw new ParseException("Failed to parse APK: {$this->buildPath}. Error: {$e->getMessage()}");
    }
  }

  private function buildExt() {
    if (isset($this->buildType)) {
      return strtolower($this->buildType);
    } else {
      return strtolower(pathinfo($this->buildPath, PATHINFO_EXTENSION));
    }
    
  }

  private function validateArguments() {
    if (!file_exists($this->buildPath)) {
      throw new ArgumentException("Build file does not exist: {$this->buildPath}");
    }

    $ext = $this->buildExt();
    $allowedExts = [BuildMeta::IPA_EXT, BuildMeta::APK_EXT];
    $allowedExtsAsString = implode(", ", $allowedExts);    
    if (!in_array($ext, $allowedExts)) {
      throw new ArgumentException("Wrong build type: {$ext}. Should be one of: {$allowedExtsAsString}.");
    }

    $iconOutputFolderPath = dirname($this->iconOutputPath);
    if (isset($this->iconOutputPath) && !file_exists($iconOutputFolderPath)) {
      throw new ArgumentException("Icon output folder does not exist: {$iconOutputFolderPath}");
    }

    if (isset($this->tmpFolderPath) && !file_exists($this->tmpFolderPath)) {
      throw new ArgumentException("Temp folder does not exist: {$this->tmpFolderPath}");
    }
  }

  private function cleanup() {
    $this->recursiveRmdir($this->outputFolderPath);
  }

  private function unzipResultToString($result) {
    switch ($result) {
      case \ZipArchive::ER_EXISTS:
        return "File already exists.";
        break;
      case \ZipArchive::ER_INCONS:
        return "Zip archive inconsistent.";
        break;
      case \ZipArchive::ER_INVAL:
        return "Invalid argument.";
        break;
      case \ZipArchive::ER_MEMORY:
        return "Malloc failure.";
        break;
      case \ZipArchive::ER_NOENT:
        return "No such file.";
        break;
      case \ZipArchive::ER_NOZIP:
        return "Not a zip archive.";
        break;
      case \ZipArchive::ER_OPEN:
        return "Can't open file.";
        break;
      case \ZipArchive::ER_READ:
        return "Read error.";
        break;
      case \ZipArchive::ER_SEEK:
        return "Seek error.";
        break;      
      default:
        return "Unknown error.";
        break;
    }
  }

  private function unzipIpa() {
    $outputFolderPath = $this->getOutputFolderPath();
    $this->outputFolderPath = $outputFolderPath;
    mkdir($outputFolderPath);
    $fileName = pathinfo($this->buildPath, PATHINFO_FILENAME);    
    $zipPath = $this->joinPaths($outputFolderPath, "{$fileName}.zip");
    copy($this->buildPath, $zipPath);

    $zip = new \ZipArchive;
    $res = $zip->open($zipPath);

    if ($res === TRUE) {
      $zip->extractTo($outputFolderPath);
      $zip->close();
    } else {
      $error = $this->unzipResultToString($res);
      throw new ParseException("Failed to unzip file: {$zipPath}. Error: {$error}");
    }

    $payloadFolderPath = $this->joinPaths($outputFolderPath, "Payload");
    $appFolderPath = glob($this->joinPaths($payloadFolderPath, "*.app"))[0] ?? null;
    
    if (isset($appFolderPath)) {
      return $appFolderPath;  
    } else {
      throw new ParseException("App folder not found at location: {$appFolderPath}");
    }
  }

  private function parseInfoPlist($appFolderPath) {
    $infoPlistPath = $this->joinPaths($appFolderPath, "Info.plist");
    
    if (!file_exists($infoPlistPath)) {
      throw new ParseException("Info.plist not found at location: {$infoPlistPath}");   
    }
    
    try {
      $plist = new CFPropertyList($infoPlistPath, CFPropertyList::FORMAT_AUTO);
      $info = $plist->toArray();
    } catch (\Exception $e) {
      throw new ParseException("Failed to read Info.plist: {$infoPlistPath}. Error: {$e->getMessage()}");
    }

    $this->name = $info["CFBundleDisplayName"] ?? null;
    $this->version = $info["CFBundleShortVersionString"] ?? null;
    $this->build = $info["CFBundleVersion"] ?? null;
    $this->bundleId = $info["CFBundleIdentifier"] ?? null;
    $this->minOS = $info["MinimumOSVersion"] ?? null;
    $this->iconName = $info["CFBundleIcons"]["CFBundlePrimaryIcon"]["CFBundleIconName"] ?? BuildMeta::IPA_DEFAULT_ICON_NAME;
  }

  private function convertCgBIToPNG($source, $dest) {
    try {
      CgBIParser::fix($source, $dest);
    } catch (\Exception $e) {
      throw new ParseException("Failed to convert CgBI PNG to regular PNG: {$e->getMessage()}");
    }
  }

  private function parseAppIcon($appFolderPath) {
    $appIconPaths = glob($this->joinPaths($appFolderPath, "{$this->iconName}*.png"));
    usort($appIconPaths, function (string $pathA, string $pathB) {
      return filesize($pathA) - filesize($pathB);
    });

    if (isset($this->iconOutputPath) && !empty($appIconPaths)) {
      $appIconPath = end($appIconPaths);
      $this->convertCgBIToPNG($appIconPath, $this->iconOutputPath);
    }
  }

  private function getOutputFolderPath() {
    $hrtime = hrtime();
    $timestamp = "{$hrtime[0]}{$hrtime[1]}";
    $suffix = rand(1000, 9999);
    $outputFolderName = "build_meta_{$timestamp}_{$suffix}";
    $tmpFolderPath = $this->tmpFolderPath ?? sys_get_temp_dir();
    return $this->joinPaths($tmpFolderPath, $outputFolderName);
  }

  private function joinPaths(string ...$paths) {
    $pathValues = array_values($paths);
    $pathValuesCount = count($pathValues);

    if ($pathValuesCount == 0) {
      return "";
    }

    if ($pathValuesCount == 1) {
      return $pathValues[0];
    }

    foreach ($pathValues as $key => &$value) {
      if ($key == 0) {
        $value = rtrim($value, DIRECTORY_SEPARATOR);
      } else if ($key == $pathValuesCount - 1) {
        $value = ltrim($value, DIRECTORY_SEPARATOR);
      } else {
        $value = trim($value, DIRECTORY_SEPARATOR);        
      }
    }

    return implode(DIRECTORY_SEPARATOR, $pathValues);
  }

  private function recursiveRmdir($dir) {
    if (!isset($dir)) { return; }    
    $files = array_diff(scandir($dir), array('.','..')); 
    foreach ($files as $file) {
      if (is_dir("$dir/$file")) {
        $this->recursiveRmdir("$dir/$file");
      } else {
        unlink("$dir/$file");
      }
    }
    return rmdir($dir); 
  }
}