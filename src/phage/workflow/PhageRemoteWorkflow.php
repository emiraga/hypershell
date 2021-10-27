<?php

final class PhageRemoteWorkflow
  extends PhageWorkflow {

  public function getWorkflowName() {
    return 'remote';
  }

  public function getWorkflowArguments() {
    return array(
      $this->newWorkflowArgument('hosts')
        ->setParameter('hosts')
        ->setHelp(pht('Run on hosts.')),
      $this->newWorkflowArgument('pools')
        ->setParameter('pools')
        ->setHelp(pht('Run on pools.')),
      $this->newWorkflowArgument('limit')
        ->setParameter('count')
        ->setHelp(pht('Limit parallelism.')),
      $this->newWorkflowArgument('throttle')
        ->setParameter('seconds')
        ->setHelp(pht('Wait this many seconds between commands.')),
      $this->newWorkflowArgument('args')
        ->setHelp(pht('Arguments to pass to "bin/remote".'))
        ->setWildcard(true),
      $this->newWorkflowArgument('timeout')
        ->setParameter('seconds')
        ->setHelp(pht('Command timeout in seconds.')),
    );
  }

  public function getWorkflowInformation() {
    return $this->newWorkflowInformation()
      ->addExample(pht('**remote** --hosts __hosts__ [__options__] -- __command__'))
      ->setSynopsis(pht('Run a "bin/remote" command on a group of hosts.'));
  }

  protected function runWorkflow() {
    $hosts = $this->getArgument('hosts');
    $pools = $this->getArgument('pools');

    if (!strlen($hosts) && !strlen($pools)) {
      throw new PhutilArgumentUsageException(
        pht(
          'Provide a list of hosts to execute on with "--hosts", or a '.
          'list of host pools with "--pools".'));
    }

    $remote_args = $this->getArgument('args');
    if (!$remote_args) {
      throw new PhutilArgumentUsageException(
        pht('Provide a remote command to execute.'));
    }

    $limit = $this->getArgument('limit');
    $throttle = $this->getArgument('throttle');
    $timeout = $this->getArgument('timeout');

    $hosts = $this->expandHosts($hosts, $pools);
    $plan = new PhagePlanAction();

    $ssh_action = new PhageSshAction();

    if ($limit) {
      $ssh_action->setLimit($limit);
    }

    if ($throttle) {
      $ssh_action->setThrottle($throttle);
    }

    $plan->addAction($ssh_action);

    $commands = array();
    foreach ($hosts as $host) {
      $host_args = $remote_args;

      $command = csprintf('ssh -o StrictHostKeyChecking=no %s %Ls', $host, $host_args);

      $execute = id(new PhageExecuteAction())
        ->setLabel($host)
        ->setCommand($command);

      if ($timeout) {
        $execute->setTimeout($timeout);
      }

      $commands[] = $execute;

      $ssh_action->addAction($execute);
    }

    $t_start = microtime(true);
    $plan->executePlan();
    $t_end = microtime(true);

    $okay_count = 0;
    foreach ($commands as $command) {
      $exit_code = $command->getExitCode();

      if ($exit_code !== 0) {
        echo tsprintf(
          "**<bg:red> [%s] </bg>** %s\n",
          $command->getLabel(),
          pht(
            'Command failure (%d).',
            $exit_code));
      } else {
        $okay_count++;
      }
    }

    if ($okay_count === count($commands)) {
      echo tsprintf(
        "**<bg:green> %s </bg>** %s\n",
        pht('COMPLETE'),
        pht(
          'Everything went according to plan (in %sms).',
          new PhutilNumber(1000 * ($t_end - $t_start))));
    }

  }

  private function expandHosts($spec, $pools) {
    $parts = preg_split('/[, ]+/', $spec);
    $parts = array_filter($parts);

    $hosts = array();
    foreach ($parts as $part) {
      $matches = null;
      $ok = preg_match_all('/(\d+-\d+)/', $part, $matches, PREG_OFFSET_CAPTURE);

      // If there's nothing like "001-12" in the specification, just use the
      // raw host as provided.
      if (!$ok) {
        $hosts[] = $part;
        continue;
      }

      if (count($matches[1]) > 1) {
        throw new Exception(
          pht(
            'Host specification "%s" is ambiguous.',
            $part));
      }

      $match = $matches[1][0][0];
      $offset = $matches[1][0][1];

      $range = explode('-', $match, 2);
      $width = strlen($range[0]);
      $min = (int)$range[0];
      $max = (int)$range[1];

      if ($min > $max) {
        throw new Exception(
          pht(
            'Host range "%s" is invalid: minimum is larger than maximum.',
            $match));
      }

      if (strlen($max) > $width) {
        throw new Exception(
          pht(
            'Host range "%s" is invalid: range start does not have enough '.
            'leading zeroes to contain the entire range.',
            $match));
      }

      $values = range($min, $max);
      foreach ($values as $value) {
        $value = sprintf("%0{$width}d", $value);
        $host = substr_replace($part, $value, $offset, strlen($match));
        $hosts[] = $host;
      }
    }

    if (strlen($pools)) {
      $bin_remote = PhacilityCore::getCorePath('bin/remote');

      list($stdout) = execx(
        '%R list-hosts %R',
        $bin_remote,

        // NOTE: This is a dummy argument, but "bin/remote" currently requires
        // a host target even if we're just going directly to the bastion. This
        // could be cleaned up at some point.
        'bastion-external.phacility.net');

      $pool_hosts = phutil_json_decode($stdout);

      $prefixes = preg_split('/[, ]+/', $pools);
      $prefixes = array_fill_keys($prefixes, array());
      foreach ($pool_hosts as $pool_host) {
        $host = $pool_host['host'];

        foreach ($prefixes as $prefix => $host_list) {
          if (preg_match('(^'.preg_quote($prefix).'\d)', $host)) {
            $prefixes[$prefix][] = $host;
          }
        }
      }

      foreach ($prefixes as $prefix => $host_list) {
        if (!$host_list) {
          throw new Exception(
            pht(
              'Pool "%s" matched no hosts. Use real pools which contain '.
              'actual hosts.',
              $prefix));
        }

        foreach ($host_list as $host) {
          $hosts[] = $host;
        }
      }
    }

    return $hosts;
  }

}
