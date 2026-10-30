<?php
// A symfony/console progress bar, as an example of what PHP console programs print.
require __DIR__ . '/../../../vendor/autoload.php';

use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\ConsoleOutput;

$output = new ConsoleOutput(ConsoleOutput::VERBOSITY_NORMAL, true);
$output->writeln('<info>Importing</info> <comment>records</comment>');
$bar = new ProgressBar($output, 40);
$bar->setFormat('very_verbose');
$bar->setRedrawFrequency(1);
$bar->minSecondsBetweenRedraws(0);
$bar->start();
for ($i = 0; $i < 40; $i++) {
    usleep(5000);
    $bar->advance();
}
$bar->finish();
$output->writeln('');
$output->writeln('<error>done</error> with <fg=cyan;options=bold>colors</>');
