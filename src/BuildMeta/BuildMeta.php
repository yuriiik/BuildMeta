<?php

namespace BuildMeta;

use BuildMeta\Error\{ParseError, ArgumentError};
use Alchemy\Zippy\Zippy;
use CFPropertyList\CFPropertyList;
use ApkParser\Parser as ApkParser;

/**
 * 
 */
class BuildMeta
{  
  public $name;
  public $version;
  public $build;
  public $bundleId;
  public $minOS;  
  
  function __construct(string $buildPath, string $iconOutputPath = null, string $tmpFolderPath = null) {
    $this->buildPath = $buildPath;
    $this->iconOutputPath = $iconOutputPath;
    $this->tmpFolderPath = $tmpFolderPath;
  }

  function parse() {
    $this->validateArguments();
    try {
      if ($this->buildExt() === BuildMeta::IPA_EXT) {
        $this->parseIpa();
      } elseif ($this->buildExt() === BuildMeta::APK_EXT) {
        $this->parseApk();
      }
    } catch (\Error $e) {
      throw $e;
    } finally {
      $this->cleanup();
    }
  }

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
        $stream = stream_get_contents($apk->getStream($appIconResource));
        $imageResource = imagecreatefromstring($stream);
        imagepng($imageResource, $this->iconOutputPath);
        imagedestroy($imageResource);
      }
    } catch (\Throwable $t) {
      throw new ParseError("Failed to parse APK: {$this->buildPath}. Error: {$t->getMessage()}");
    }
  }

  private const IPA_DEFAULT_ICON_NAME = 'AppIcon';
  private const IPA_EXT = 'ipa';
  private const APK_EXT = 'apk';

  private $buildPath;
  private $iconOutputPath;
  private $tmpFolderPath;
  private $outputFolderPath;
  private $iconName;

  private function buildExt() {
    return strtolower(pathinfo($this->buildPath, PATHINFO_EXTENSION));
  }

  private function validateArguments() {
    if (!file_exists($this->buildPath)) {
      throw new ArgumentError("Build file does not exist: {$this->buildPath}");
    }

    $allowedExts = [BuildMeta::IPA_EXT, BuildMeta::APK_EXT];
    $allowedExtsAsString = implode(", ", $allowedExts);
    if (!in_array($this->buildExt(), $allowedExts)) {
      throw new ArgumentError("Wrong build file: {$ext}. Should be one of: {$allowedExtsAsString}.");
    }

    $iconOutputFolderPath = dirname($this->iconOutputPath);
    if (isset($this->iconOutputPath) && !file_exists($iconOutputFolderPath)) {
      throw new ArgumentError("Icon output folder does not exist: {$iconOutputFolderPath}");
    }

    if (isset($this->tmpFolderPath) && !file_exists($this->tmpFolderPath)) {
      throw new ArgumentError("Temp folder does not exist: {$this->tmpFolderPath}");
    }
  }

  private function cleanup() {
    $this->recursiveRmdir($this->outputFolderPath);
  }

  private function unzipIpa() {
    $outputFolderPath = $this->getOutputFolderPath();
    $this->outputFolderPath = $outputFolderPath;
    mkdir($outputFolderPath);
    $fileName = pathinfo($this->buildPath, PATHINFO_FILENAME);    
    $zipPath = $this->joinPaths($outputFolderPath, "{$fileName}.zip");
    copy($this->buildPath, $zipPath);

    try {
      Zippy::load()
      ->open($zipPath)
      ->extract($outputFolderPath);
    } catch (\Throwable $t) {
      throw new ParseError("Failed to unzip file: {$zipPath}. Error: {$t->getMessage()}");
    }

    $payloadFolderPath = $this->joinPaths($outputFolderPath, "Payload");
    $appFolderPath = glob($this->joinPaths($payloadFolderPath, "*.app"))[0] ?? null;
    
    if (isset($appFolderPath)) {
      return $appFolderPath;  
    } else {
      throw new ParseError("App folder not found at location: {$appFolderPath}");
    }
  }

  private function parseInfoPlist($appFolderPath) {
    $infoPlistPath = $this->joinPaths($appFolderPath, "Info.plist");
    
    if (!file_exists($infoPlistPath)) {
      throw new ParseError("Info.plist not found at location: {$infoPlistPath}");   
    }
    
    try {
      $plist = new CFPropertyList($infoPlistPath, CFPropertyList::FORMAT_AUTO);
      $info = $plist->toArray();
    } catch (\Throwable $t) {
      throw new ParseError("Failed to read Info.plist: {$infoPlistPath}. Error: {$t->getMessage()}");
    }

    $this->name = $info["CFBundleDisplayName"] ?? null;
    $this->version = $info["CFBundleShortVersionString"] ?? null;
    $this->build = $info["CFBundleVersion"] ?? null;
    $this->bundleId = $info["CFBundleIdentifier"] ?? null;
    $this->minOS = $info["MinimumOSVersion"] ?? null;
    $this->iconName = $info["CFBundleIcons"]["CFBundlePrimaryIcon"]["CFBundleIconName"] ?? BuildMeta::IPA_DEFAULT_ICON_NAME;
  }

  private function parseAppIcon($appFolderPath) {
    $appIconPaths = glob($this->joinPaths($appFolderPath, "{$this->iconName}*.png"));
    usort($appIconPaths, function (string $pathA, string $pathB) {
      return filesize($pathA) - filesize($pathB);
    });

    if (isset($this->iconOutputPath) && !empty($appIconPaths)) {
      $appIconPath = end($appIconPaths);
      copy($appIconPath, $this->iconOutputPath);
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