#!/usr/bin/env php
<?php

require_once __DIR__ . '/vendor/autoload.php';

use PhpParser\ParserFactory;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Node;
use PhpParser\PrettyPrinter\Standard;
use Symfony\Component\Console\Input\ArgvInput;
use PhpParser\NodeVisitor\NameResolver;



$input = new ArgvInput();
$targetDir = $input->getParameterOption('--target-dir');
$dryRun = $input->hasParameterOption('--dry-run');

if (!$targetDir || !is_dir($targetDir)) {
    echo "Invalid or missing --target-dir\n";
    exit(1);
}


//$parser = (new ParserFactory)->createForNewestSupportedVersion();
$parser = (new ParserFactory)->createForNewestSupportedVersion();
//(ParserFactory::PREFER_PHP7);

$prettyPrinter = new Standard();

$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($targetDir));
$files = iterator_to_array(new RegexIterator($rii, '/\.php$/'));

foreach ($files as $file) {
  //  echo "Scanning file: {$file}\n";
    $code = file_get_contents($file);
    try {
        $ast = $parser->parse($code);
    } catch (Throwable $e) {
        continue;
    }

    $traverser = new NodeTraverser();
    $visitor = new class($file, $dryRun) extends NodeVisitorAbstract {
        private $file;
        private $dryRun;
        private $modified = false;

        private Standard $printer;

        public function __construct($file, $dryRun)
        {
            $this->file = $file;
            $this->dryRun = $dryRun;
            $this->printer = new Standard();
        }

        public function enterNode(Node $node)
        {
            if ($node instanceof Node\Stmt\Class_ && $node->extends) {
                $parentClass = $node->extends->toString();
            //    echo "Found class: {$node->name} extends {$parentClass}\n";

                if (strpos(ltrim($parentClass, '\\'), 'Symfony\\Component\\') !== 0) {
                 //   echo "  => Skipped: Not a Symfony\Component class\n";
                    return;
                }

                foreach ($node->getMethods() as $method) {
                    if ($method->returnType !== null) {
                       // echo "  => Skipped method {$method->name->name}: Already has return type\n";
                        continue;
                    }

                    try {
                        $refParent = new ReflectionClass($parentClass);
                        if (!$refParent->hasMethod($method->name->name)) {
                        //    echo "  => Parent class does not have method {$method->name->name}\n";
                            continue;
                        }

                        $parentMethod = $refParent->getMethod($method->name->name);
                        if ($parentMethod->hasReturnType()) {
                            $parentReturnType = $parentMethod->getReturnType();
                            $typeStr = (string)$parentReturnType;
                            $newType = new Node\Identifier($typeStr);
                            if ($parentReturnType->allowsNull()) {
                                $newType = new Node\NullableType($newType);
                                $typeStr = '?' . $typeStr;
                            }

                            if ($this->dryRun) {
                                $red = "\033[31m";     // Red
                                $green = "\033[32m";   // Green
                                $reset = "\033[0m";

                                // Get the old line from the source file
                                $lines = explode("\n", file_get_contents($this->file));
                                $startLine = $method->getStartLine() - 1;
                                $oldLine = trim($lines[$startLine]);

                                // Build new method signature
                                $methodName = $method->name->name;
                                $visibility = $this->getVisibility($method);
                                $isStatic = $method->isStatic() ? 'static ' : '';
                                $params = implode(', ', array_map(function ($param) {
                                    return $this->prettyPrintExpr($param);
                                }, $method->params));
                                $returnType = $newType;

                                $newLine = "{$visibility} {$isStatic}function {$methodName}({$params}): {$returnType}";

                                echo "[Dry Run] Would update:\n";
                                echo "File: {$this->file}\n";
                                echo "  - Method: {$methodName} (add return type: {$newType})\n";
                                echo "  {$red}- {$oldLine}{$reset}\n";
                                echo "  {$green}+ {$newLine}{$reset}\n\n";
                            } else {
                                $method->returnType = $newType;
                                $this->modified = true;
                            }
                        } else {
                      //      echo "  => Parent method {$method->name->name} has no return type\n";
                        }
                    } catch (Throwable $e) {
                     //   echo "  => Reflection failed: {$e->getMessage()}\n";
                    }
                }
            }
        }
        private function getVisibility(Node\Stmt\ClassMethod $method): string
        {
            if ($method->isPublic()) {
                return 'public';
            }
            if ($method->isProtected()) {
                return 'protected';
            }
            if ($method->isPrivate()) {
                return 'private';
            }
            return '';
        }

        private function prettyPrintExpr($param): string
        {
            $type = $param->type ? $this->printer->prettyPrint([$param->type]) . ' ' : '';
            $byRef = $param->byRef ? '&' : '';
            $variadic = $param->variadic ? '...' : '';
            return "{$type}{$byRef}{$variadic}\${$param->var->name}";
        }

        public function afterTraverse(array $nodes)
        {
            if (!$this->dryRun && $this->modified) {
                $prettyPrinter = new Standard();
                $newCode = $prettyPrinter->prettyPrintFile($nodes);
                file_put_contents($this->file, $newCode);
                echo "[Updated] {$this->file}\n";
            }
        }
    };
    $traverser->addVisitor(new NameResolver());

    $traverser->addVisitor($visitor);
    $traverser->traverse($ast);
}
