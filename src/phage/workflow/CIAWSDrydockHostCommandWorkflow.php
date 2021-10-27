<?php

final class CIAWSDrydockHostCommandWorkflow extends CIAWSManagementWorkflow {

  protected function didConstruct() {
    $this
      ->setName('drydock-command')
      ->setSynopsis(pht('Interact with a drydock host.'))
      ->setArguments([
          [
            'name'        => 'lease',
            'param'       => 'id',
            'help'        => pht('ID of drydock lease to command.'),
          ],
        ]);
  }

  public function execute(PhutilArgumentParser $args) {
    $resource_id = $this->requireArgument($args, 'lease');
    $viewer = PhabricatorUser::getOmnipotentUser();
    $lease = (new DrydockLeaseQuery())
      ->setViewer($viewer)
      ->withIDs([$resource_id])
      ->executeOne();

    $boot = new PhagePHPAgentBootloader();
    $ssh = $lease->getInterface(DrydockCommandInterface::INTERFACE_TYPE);
    $exec = $ssh->getExecFuture('%C', $boot->getBootCommand());
    $exec->write($boot->getBootSequence(), $keep_open = true);
    $exec_channel = new PhutilExecChannel($exec);
    $agent = new PhutilJSONProtocolChannel($exec_channel);

    $console = PhutilConsole::getConsole();
    $cmd = null;
    $key = 1;
    while ($cmd !== 'exit') {
      $console->writeOut('**>** ');
      $cmd = fgets(STDIN);
      $cmd = rtrim($cmd, "\r\n");
      $agent->write([
        'type' => 'EXEC',
        'key' => $key++,
        'command' => $cmd,
      ]);
      $message = $agent->waitForMessage();
      $console->writeOut('%s', $message['stdout']);
      $console->writeErr('%s', $message['stderr']);
    }
    $agent->write(['type' => 'EXIT']);
    return 0;
  }

}
