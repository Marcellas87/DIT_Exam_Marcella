<?php
declare(strict_types=1);

/**
 * Documentation checker: lists every named function or method whose doc comment is missing,
 * or that lacks an @param for one of its parameters, an @return for a returned value,
 * or an @throws when its body throws.
 * Checks src/, the pages in the project root, tests/ and tools/.
 * Run:  php tools/check_docs.php
 *
 * @author Marcella
 * @version 1.1
 */

/** Token types that may stand between a doc comment and the word "function". */
const MODIFIER_TOKENS = [T_WHITESPACE, T_PUBLIC, T_PRIVATE, T_PROTECTED, T_STATIC, T_FINAL, T_ABSTRACT];

/**
 * Returns the text of a token (tokens are either arrays or single characters).
 *
 * @param array<int, mixed>|string $token one token from token_get_all()
 * @return string the token text
 */
function getTokenText($token): string
{
    return is_array($token) ? (string)$token[1] : $token;
}

/**
 * Collects token text from a position until the brackets opened there are closed again.
 *
 * @param array<int, mixed> $tokens all tokens of the file
 * @param int $position where to start; moved to the closing bracket
 * @param string $openBracket "(" or "{"
 * @param string $closeBracket ")" or "}"
 * @return string the collected text
 */
function readBracketBlock(array $tokens, int &$position, string $openBracket, string $closeBracket): string
{
    $text = '';
    $depth = 0;
    for ($tokenCount = count($tokens); $position < $tokenCount; $position++) {
        $tokenText = getTokenText($tokens[$position]);
        $text .= $tokenText;
        if ($tokenText === $openBracket) {
            $depth++;
        } elseif ($tokenText === $closeBracket) {
            $depth--;
            if ($depth === 0) {
                break;
            }
        }
    }
    return $text;
}

/**
 * Checks one file and prints a line for every function with documentation gaps.
 *
 * @param string $file the PHP file to check
 * @return int the number of functions with gaps
 */
function checkFile(string $file): int
{
    $tokens = token_get_all((string)file_get_contents($file));
    $tokenCount = count($tokens);
    $docComment = null;
    $problemCount = 0;

    for ($position = 0; $position < $tokenCount; $position++) {
        $token = $tokens[$position];
        if (!is_array($token)) {
            $docComment = null;
            continue;
        }
        if ($token[0] === T_DOC_COMMENT) {
            $docComment = $token[1];
            continue;
        }
        if (in_array($token[0], MODIFIER_TOKENS, true)) {
            continue;
        }
        if ($token[0] !== T_FUNCTION) {
            if ($token[0] !== T_COMMENT) {
                $docComment = null;
            }
            continue;
        }

        // Find the function name; a closure has "(" here instead, and is skipped.
        $namePosition = $position + 1;
        while (is_array($tokens[$namePosition]) && $tokens[$namePosition][0] === T_WHITESPACE) {
            $namePosition++;
        }
        if (!is_array($tokens[$namePosition])) {
            $docComment = null;
            continue;
        }
        $functionName = $tokens[$namePosition][1];

        $signaturePosition = $namePosition;
        $signature = readBracketBlock($tokens, $signaturePosition, '(', ')');
        preg_match_all('/\$(\w+)/', $signature, $parameterMatches);
        $parameterNames = array_unique($parameterMatches[1]);

        // Text between ")" and "{" or ";" holds the return type.
        $returnTypeText = '';
        $bodyPosition = $signaturePosition + 1;
        for (; $bodyPosition < $tokenCount; $bodyPosition++) {
            $tokenText = getTokenText($tokens[$bodyPosition]);
            if ($tokenText === '{' || $tokenText === ';') {
                break;
            }
            $returnTypeText .= $tokenText;
        }
        $returnsValue = $functionName !== '__construct'
            && preg_match('/:\s*\??\s*(?!void\b)\w/', $returnTypeText) === 1;
        $body = ($bodyPosition < $tokenCount && getTokenText($tokens[$bodyPosition]) === '{')
            ? readBracketBlock($tokens, $bodyPosition, '{', '}')
            : '';
        $throwsException = preg_match('/\bthrow\s+new\b/', $body) === 1;

        $missingParts = [];
        if ($docComment === null) {
            $missingParts[] = 'NO DOC';
        } else {
            foreach ($parameterNames as $parameterName) {
                if (strpos($docComment, '$' . $parameterName) === false) {
                    $missingParts[] = '@param $' . $parameterName;
                }
            }
            if ($returnsValue && strpos($docComment, '@return') === false) {
                $missingParts[] = '@return';
            }
            if ($throwsException && strpos($docComment, '@throws') === false) {
                $missingParts[] = '@throws';
            }
        }
        if ($missingParts !== []) {
            $problemCount++;
            printf("%-34s %-26s %s\n", substr($file, strlen(dirname(__DIR__)) + 1), $functionName . '()', implode(', ', $missingParts));
        }
        $docComment = null;
        $position = $bodyPosition;
    }
    return $problemCount;
}

$projectFolder = dirname(__DIR__);
$files = array_merge(
    glob("$projectFolder/src/*.php") ?: [],
    glob("$projectFolder/*.php") ?: [],
    glob("$projectFolder/tests/*.php") ?: [],
    glob("$projectFolder/tests/phpunit/*.php") ?: [],
    glob("$projectFolder/tools/*.php") ?: []
);
$totalProblems = 0;
foreach ($files as $file) {
    $totalProblems += checkFile($file);
}
echo "\nFiles checked: " . count($files) . "\nFunctions with documentation gaps: $totalProblems\n";
exit($totalProblems === 0 ? 0 : 1);