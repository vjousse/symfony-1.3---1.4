#!/usr/bin/env php
<?php

/*
 * This file is part of the sfGitMirror.
 * (c) 2010 Konstantin Kudryashov <ever.zet@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * gitMirror implements mirroring script.
 *
 * @package    sfGitMirror
 * @subpackage sync
 * @author     Konstantin Kudryashov <ever.zet@gmail.com>
 * @version    1.0.0
 */
class gitMirror
{
  protected $svn_url;
  protected $rep_path;
  protected $trunk_branch;

  public $svn_version_tag_pattern = '/RELEASE_(\d+)_(\d+)_(\d+)(?:_(\w*))?/';
  public $git_version_tag_pattern = '/v(\d+)\.(\d+)\.(\d+)(?:-(\w*))?/';

  public $svn_full_version = 'RELEASE_%d_%d_%d_%s';
  public $svn_version      = 'RELEASE_%d_%d_%d';
  public $git_full_version = 'v%d.%d.%d-%s';
  public $git_version      = 'v%d.%d.%d';

  public function __construct($svn_url, $trunk_branch, $rep_path)
  {
    $this->svn_url = $svn_url;
    $this->trunk_branch = $trunk_branch;
    $this->rep_path = $rep_path;
  }

  protected function log($string)
  {
    printf("%s\n", $string);
  }

  protected function run($cmd)
  {
    $temp = '';
    exec(sprintf('cd %s && %s', $this->rep_path, $cmd), $temp);

    return $temp;
  }

  protected function getVersionFromTag($tag, $pattern)
  {
    preg_match($pattern, $tag, $version);

    if (count($version) > 3)
    {
      return array(
        intval($version[1]), intval($version[2]), intval($version[3]),
        isset($version[4]) ? $version[4] : false
      );
    }

    return false;
  }

  public function getSvnVersions()
  {
    $versions = array();
    $temp = $this->run(sprintf('svn list %s/tags/', $this->svn_url));

    foreach ($temp as $tag)
    {
      if ($version = $this->getVersionFromTag($tag, $this->svn_version_tag_pattern))
      {
        $versions[] = $version;
      }
    }

    return $versions;
  }

  public function getGitVersions()
  {
    $versions = array();
    $temp = $this->run('git tag');

    foreach ($temp as $tag)
    {
      if ($version = $this->getVersionFromTag($tag, $this->git_version_tag_pattern))
      {
        $versions[] = $version;
      }
    }

    return $versions;
  }

  protected function removeVersionsNotIn(array $versions, array $from)
  {
    if (null !== $from)
    {
      foreach ($versions as $i => $version)
      {
        if ($version[0] < $from[0])
        {
          unset($versions[$i]);
        }
        elseif ($version[0] === $from[0])
        {
          if ($version[1] < $from[1])
          {
            unset($versions[$i]);
          }
          elseif ($version[1] === $from[1])
          {
            if ($version[2] < $from[2])
            {
              unset($versions[$i]);
            }
          }
        }
      }
    }

    return $versions;
  }

  protected function diffVersions(array $svn_versions, array $git_versions)
  {
    $diff_versions = array();

    foreach ($svn_versions as $svn_version)
    {
      if (!in_array($svn_version, $git_versions))
      {
        $diff_versions[] = $svn_version;
      }
    }

    return $diff_versions;
  }

  protected function getSVNTagFromVersion(array $version)
  {
    if (false === $version[3])
    {
      return sprintf($this->svn_version, $version[0], $version[1], $version[2]);
    }
    else
    {
      return sprintf($this->svn_full_version, $version[0], $version[1], $version[2], $version[3]);
    }
  }

  protected function getGitTagFromVersion(array $version)
  {
    if (false === $version[3])
    {
      return sprintf($this->git_version, $version[0], $version[1], $version[2]);
    }
    else
    {
      return sprintf($this->git_full_version, $version[0], $version[1], $version[2], $version[3]);
    }
  }

  protected function mirrorVersion(array $version)
  {
    $svn_tag = $this->getSVNTagFromVersion($version);
    $git_tag = $this->getGitTagFromVersion($version);

    $this->run(sprintf('git checkout -b %s', $svn_tag));
    $this->run(sprintf('svn switch %s/tags/%s', $this->svn_url, $svn_tag));
    $this->run('git add .');
    $this->run(sprintf("git commit -am '%s => %s commit'", $svn_tag, $git_tag));
    $this->run('git checkout master');
    $this->run(sprintf('git merge %s', $svn_tag));
    $this->run(sprintf('git branch -D %s', $svn_tag));
    $this->run(sprintf("git tag -am 'version %s tag' %s", $git_tag, $git_tag));
  }
  
  protected function isExcluded($version, array $stringsToExclude)
  {
    foreach($stringsToExclude as $string)
    {
      if(strpos($version,$string) !== false)
      {
        return true;
      }
    }
    
    return false;
  }

  public function sync(array $from,array $stringsToExclude)
  {
    if (!is_dir($this->rep_path))
    {
      mkdir($this->rep_path);
    }
    if (!is_dir(sprintf('%s/.svn', $this->rep_path)))
    {
      $this->log('Creating SVN clone');
      $this->run(sprintf('svn checkout %s/branches/%s .', $this->svn_url, $this->trunk_branch));
    }
    if (!is_dir(sprintf('%s/.git', $this->rep_path)))
    {
      $this->log('Creating Git repo');
      $this->run('git init');
      file_put_contents(sprintf('%s/.gitignore', $this->rep_path), ".svn\n");
      $this->run('git add .');
      $this->run("git commit -am 'initial commit'");
    }

    $this->run('git checkout master');

    $this->log('Getting SVN version tags');
    $svn_versions = $this->getSvnVersions();
    $svn_versions = $this->removeVersionsNotIn($svn_versions, $from);
    $this->log(sprintf("Total versions: %d\n", count($svn_versions)));

    $this->log('Getting Git version tags');
    $git_versions = $this->getGitVersions();
    $git_versions = $this->removeVersionsNotIn($git_versions, $from);
    $this->log(sprintf("Total versions: %d\n", count($git_versions)));

    $this->log('Getting versions without Git tags');
    $versions = $this->diffVersions($svn_versions, $git_versions);
    $this->log(sprintf("Total versions: %d\n", count($versions)));

    foreach ($versions as $version)
    {
      if(!$version[3] || $this->isExcluded($version[3],$stringsToExclude) === false)
      {
        $this->log(sprintf('Mirroring %s', $this->getGitTagFromVersion($version)));
        $this->mirrorVersion($version);
      }
    }

    $this->log("Sync edge\n");
    $this->run('git branch edge');
    $this->run('git checkout edge');
    $this->run(sprintf("svn switch %s/branches/%s", $this->svn_url, $this->trunk_branch));
    $this->run('git add .');
    $this->run(sprintf("git commit -am 'edge update'"));
    $this->run('git checkout master');
  }

  public function push($remote='origin')
  {
    $this->log('Push');
    $this->run('git push ' . $remote . ' master');
    $this->run('git push ' . $remote . ' edge');
    $this->run('git push ' . $remote . ' master --tags');
  }
}

$mirror = new gitMirror('http://svn.symfony-project.com', '1.4', $argv[1]);
$mirror->sync(array(1, 3, 0),array('RC','BETA','ALPHA'));
$mirror->push();
