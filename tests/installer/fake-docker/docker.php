<?php

/**
 * Fake docker for the installer tests (tests/installer): two running
 * containers, "glpi-app", whose files live in the folder FAKE_DOCKER_ROOT, and
 * "db", without PHP. Paths of the container (arguments starting with /) are
 * moved into FAKE_DOCKER_ROOT, and moved back in what the commands print.
 *
 *   FAKE_DOCKER_ROOT    folder that plays the root of the container "glpi-app"
 *   FAKE_DOCKER_MOUNTS  volumes of the container: "DESTINATION=SOURCE;…"
 *   FAKE_DOCKER_ENV     environment of the container: "NAME=VALUE;…"
 *   FAKE_DOCKER_LOG     every call is appended to this file, one JSON array per line
 *
 * Usage: php docker.php ARGUMENT… (tests/installer/fake-docker/docker wraps it)
 */

$root = rtrim(str_replace('\\', '/', (string) getenv('FAKE_DOCKER_ROOT')), '/');
$args = array_slice($argv, 1);

if ((string) getenv('FAKE_DOCKER_LOG') !== '') {
    file_put_contents((string) getenv('FAKE_DOCKER_LOG'), json_encode($args, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
}

function fail(string $message, int $code = 1): void
{
    fwrite(STDERR, $message . "\n");
    exit($code);
}

function inside(string $path): string
{
    global $root;
    if ($root === '' || !is_dir($root)) {
        fail('FAKE_DOCKER_ROOT is not a folder');
    }
    $path = '/' . ltrim(str_replace('\\', '/', $path), '/');
    if (strpos($path, '/..') !== false) {
        fail('Path outside of the container: ' . $path);
    }
    return $root . rtrim($path, '/');
}

function outside(string $text): string
{
    global $root;
    return str_replace([$root, str_replace('/', '\\', $root)], '', $text);
}

function pairs(string $name): array
{
    $values = [];
    foreach (array_filter(explode(';', (string) getenv($name))) as $pair) {
        [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
        $values[$key] = $value;
    }
    return $values;
}

function is_option(string $arg): bool
{
    return strpos($arg, '-') === 0;
}

function container(string $name): void
{
    if (!in_array($name, ['glpi-app', 'db'], true)) {
        fail('Error response from daemon: No such container: ' . $name);
    }
}

function remove(string $path): void
{
    if (is_link($path) || is_file($path)) {
        unlink($path);
    } elseif (is_dir($path)) {
        foreach (array_diff(scandir($path), ['.', '..']) as $entry) {
            remove($path . '/' . $entry);
        }
        rmdir($path);
    }
}

function copy_tree(string $from, string $to): void
{
    if (is_dir($from)) {
        if (!is_dir($to)) {
            mkdir($to, 0755);
        }
        foreach (array_diff(scandir($from), ['.', '..']) as $entry) {
            copy_tree($from . '/' . $entry, $to . '/' . $entry);
        }
    } else {
        copy($from, $to);
    }
}

$command = array_shift($args) ?? '';
switch ($command) {
    case 'ps':
        if (in_array('-q', $args, true)) {
            echo "1f2e3d4c5b6a\n9a8b7c6d5e4f\n";
        } else {
            echo "glpi-app\ndb\n";
        }
        exit(0);

    case 'info':
        echo "Ubuntu 24.04.1 LTS\n";
        exit(0);

    case 'inspect':
        $format = '';
        $names = [];
        for ($i = 0; $i < count($args); $i++) {
            if (in_array($args[$i], ['-f', '--format'], true)) {
                $format = $args[++$i] ?? '';
            } else {
                $names[] = $args[$i];
            }
        }
        container($names[0] ?? '');
        $mounts = pairs('FAKE_DOCKER_MOUNTS');
        if (strpos($format, '{{json .Mounts}}') !== false) {
            $list = [];
            foreach ($mounts as $destination => $source) {
                $list[] = ['Type' => 'volume', 'Source' => $source, 'Destination' => $destination];
            }
            echo json_encode($list, JSON_UNESCAPED_SLASHES), "\n";
        } elseif (preg_match('/^(.*)\{\{range \.Mounts\}\}(.*)\{\{end\}\}(.*)$/s', $format, $m)) {
            echo $m[1];
            foreach ($mounts as $destination => $source) {
                echo str_replace(['{{.Destination}}', '{{.Source}}', '{{println}}'], [$destination, $source, "\n"], $m[2]);
            }
            echo $m[3], "\n";
        } elseif (strpos($format, 'NetworkMode') !== false) {
            echo "bridge\n";
        } elseif (strpos($format, '.Gateway') !== false) {
            echo "172.18.0.1\n\n";
        } else {
            echo "\n";
        }
        exit(0);

    case 'exec':
        $user = '';
        $workdir = '';
        $interactive = false;
        while ($args && is_option($args[0])) {
            $option = array_shift($args);
            if (in_array($option, ['-u', '--user'], true)) {
                $user = (string) array_shift($args);
            } elseif (in_array($option, ['-w', '--workdir'], true)) {
                $workdir = (string) array_shift($args);
            } elseif (in_array($option, ['-i', '--interactive'], true)) {
                $interactive = true;
            } elseif (!in_array($option, ['-t', '--tty'], true)) {
                fail('unknown flag: ' . $option, 125);
            }
        }
        $name = (string) array_shift($args);
        container($name);
        $program = (string) array_shift($args);
        if ($name === 'db') {
            fail('OCI runtime exec failed: exec failed: unable to start container process: exec: "' . $program . '": executable file not found in $PATH: unknown', 126);
        }
        $root_user = in_array($user, ['0', 'root'], true);
        $known = preg_match('/^' . preg_quote($user, '/') . ':/m', (string) @file_get_contents(inside('/etc/passwd')));
        if ($user !== '' && !$root_user && !preg_match('/^\d+$/', $user) && !$known) {
            fail('unable to find user ' . $user . ': no matching entries in passwd file', 126);
        }
        $cwd = $workdir !== '' ? inside($workdir) : $root;
        if (!is_dir($cwd)) {
            fail('OCI runtime exec failed: chdir to cwd ("' . $workdir . '") set in config.json failed: no such file or directory', 126);
        }
        $paths = array_map(static function ($arg) {
            return strpos($arg, '/') === 0 ? inside($arg) : $arg;
        }, $args);

        switch ($program) {
            case 'php':
                // Code given with -r: the files of the system are the ones of the container too
                foreach ($paths as $i => $arg) {
                    if ($i > 0 && $paths[$i - 1] === '-r') {
                        $paths[$i] = preg_replace("~'/(etc|proc|var/spool)/~", "'" . $root . '/$1/', $arg);
                    }
                }
                $environment = getenv();
                foreach (pairs('FAKE_DOCKER_ENV') as $key => $value) {
                    $environment[$key] = strpos($value, '/') === 0 ? inside($value) : $value;
                }
                foreach (array_keys($environment) as $key) {
                    if (strtoupper($key) === 'PATH') {
                        unset($environment[$key]);
                    }
                }
                // The PATH of the container (Windows: and the one of PHP, for its libraries)
                $environment['PATH'] = implode(':', array_map('inside', ['/usr/local/bin', '/usr/bin', '/bin']))
                    . (PHP_OS_FAMILY === 'Windows' ? ';' . getenv('PATH') : '');
                $errors = tempnam(sys_get_temp_dir(), 'fake-docker');
                $process = proc_open(
                    array_merge([PHP_BINARY], $paths),
                    [0 => $interactive ? STDIN : ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $errors, 'w']],
                    $pipes,
                    $cwd,
                    $environment,
                );
                if (!is_resource($process)) {
                    fail('cannot start PHP', 126);
                }
                if (!$interactive) {
                    fclose($pipes[0]);
                }
                $output = stream_get_contents($pipes[1]);
                fclose($pipes[1]);
                $code = proc_close($process);
                echo outside($output);
                fwrite(STDERR, outside((string) file_get_contents($errors)));
                unlink($errors);
                exit($code);

            case 'rm':
                foreach ($paths as $path) {
                    if (!is_option($path)) {
                        remove($path);
                    }
                }
                exit(0);

            case 'mkdir':
                foreach ($paths as $path) {
                    if (!is_option($path) && !is_dir($path) && !mkdir($path, 0755, true)) {
                        fail('mkdir: cannot create directory');
                    }
                }
                exit(0);

            case 'mv':
                [$from, $to] = $paths;
                if (is_dir($to)) {
                    $to .= '/' . basename($from);
                }
                exit(rename($from, $to) ? 0 : 1);

            case 'chown':
            case 'chmod':
                if (!$root_user) {
                    fail($program . ': changing ownership: Operation not permitted');
                }
                foreach ($paths as $path) {
                    if (strpos($path, $root) === 0 && !file_exists($path)) {
                        fail($program . ': cannot access ' . outside($path) . ': No such file or directory');
                    }
                }
                exit(0);
        }
        fail('OCI runtime exec failed: exec failed: unable to start container process: exec: "' . $program . '": executable file not found in $PATH: unknown', 126);
        // no break

    case 'cp':
        [$from, $to] = array_pad(array_values(array_filter($args, static function ($arg) {
            return !is_option($arg);
        })), 2, '');
        $resolve = static function (string $path): string {
            // NAME:/path is in a container; C:/path is a path of this computer
            if (preg_match('~^([A-Za-z0-9][A-Za-z0-9_.-]+):(/.*)$~', $path, $m)) {
                container($m[1]);
                return inside($m[2]);
            }
            return rtrim(str_replace('\\', '/', $path), '/');
        };
        $from = $resolve($from);
        $to = $resolve($to);
        if (!file_exists($from)) {
            fail('Error response from daemon: Could not find the file ' . outside($from));
        }
        if (is_dir($to)) {
            $to .= '/' . basename($from);
        } elseif (!is_dir(dirname($to))) {
            fail('Error response from daemon: Could not find the file ' . outside(dirname($to)));
        }
        copy_tree($from, $to);
        exit(0);

    case 'version':
    case '--version':
        echo "Docker version 27.0.0, build fake\n";
        exit(0);
}

fail('docker: unknown command: docker ' . $command);
