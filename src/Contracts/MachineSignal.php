<?php

namespace KobaltDigital\PingPong\Contracts;

/**
 * A signal about the machine rather than the app: load, memory, CPU,
 * pressure, the disk and the host facts. The machine agent sends those once
 * per machine, so the Laravel agent leaves them out, except inside an ECS
 * task: a container cannot run the machine agent, and its own readings are
 * all PingPong gets of the task.
 */
interface MachineSignal extends Collector {}
