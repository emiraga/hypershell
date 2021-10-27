<?php

final class PhageSshAction
  extends PhageAgentAction {

  protected function newAgentFuture(PhutilCommandString $command) {
    $future = id(new ExecFuture('ssh 127.0.0.1 %s', $command));
    return $future;
  }
}
