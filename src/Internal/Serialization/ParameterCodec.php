<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Internal\Serialization;

/**
 * Encodes and decodes the scalar/list/object subset of OpenAPI parameters.
 *
 * The caller supplies the expected shape while parsing because wire syntax
 * alone cannot distinguish (for example) a simple array from an object.
 *
 * @internal
 */
final readonly class ParameterCodec
{
    /**
     * Whether values on this wire are percent-encoded.
     *
     * A path or a query is built out of RFC 3986 delimiters, so a value
     * carrying one has to be escaped and RFC 6570 says how; decoding is the
     * inverse of what every client does. A header field value is none of
     * that. HTTP reads it as opaque octets, nothing in the wild escapes it,
     * and percent-decoding one rewrites a value the application will receive
     * intact: `X-Path: /a%20b` is a literal path to the client that sent it
     * and to the server that reads it, and `X-Discount: 50%` is not a broken
     * escape.
     *
     * The two mistakes are not symmetric. Decoding a value nobody encoded
     * corrupts it silently; leaving an encoded value alone only makes a
     * length or pattern assertion stricter than its author meant. So the
     * decoding is where an encoding provably exists, and nowhere else. The
     * price is real and worth naming: a header value cannot carry its own
     * style delimiter, because there is no longer an escape for it.
     */
    public function __construct(private bool $percentEncoded = true) {}

    /**
     * Wire forms a spaceDelimited separator can take. A URI cannot carry a raw
     * space, so conforming traffic percent-encodes it; the raw form is accepted
     * because callers hand us query strings that have not been normalized yet.
     *
     * @var non-empty-list<non-empty-string>
     */
    private const array SPACE_DELIMITERS = ['%20', ' '];

    /**
     * Wire forms a pipeDelimited separator can take. The OpenAPI style table
     * spells it raw, but "|" is outside the query character set, so every PSR-7
     * URI implementation rewrites it to "%7C" on the way out.
     *
     * @var non-empty-list<non-empty-string>
     */
    private const array PIPE_DELIMITERS = ['%7C', '%7c', '|'];

    /**
     * @param string|list<string>|array<string, string> $value
     */
    public function serialize(
        string $name,
        string|array $value,
        ParameterStyle $style,
        bool $explode,
    ): string {
        if ($style === ParameterStyle::DeepObject) {
            return $this->queryObject($name, $this->asObject($value));
        }

        return match ($style) {
            ParameterStyle::Simple => $this->simple($value, $explode, ','),
            ParameterStyle::Label => '.' . $this->simple($value, $explode, $explode ? '.' : ','),
            ParameterStyle::Matrix => $this->matrix($name, $value, $explode),
            ParameterStyle::Form => $this->form($name, $value, $explode),
            ParameterStyle::SpaceDelimited => $this->delimitedQuery($name, $this->asList($value), ' ', '%20'),
            ParameterStyle::PipeDelimited => $this->delimitedQuery($name, $this->asList($value), '|', '|'),
            ParameterStyle::DeepObject => throw new \LogicException('Deep object is handled above'),
        };
    }

    /**
     * Strips the optional whitespace RFC 9110 allows around a header list
     * separator. PSR-7 joins repeated header lines with ", ", so a list read
     * from a header carries it whichever way the client sent the value.
     */
    public function withoutHeaderWhitespace(string $wire): string
    {
        return implode(',', array_map(trim(...), explode(',', $wire)));
    }

    /**
     * @return string|list<string>|array<string, string>
     */
    public function parse(
        string $name,
        string $wire,
        ParameterStyle $style,
        bool $explode,
        ParameterKind $kind,
    ): string|array {
        return match ($style) {
            ParameterStyle::Simple => $this->parseSimple($wire, $explode, $kind, ','),
            ParameterStyle::Label => $this->parseSimple($this->withoutPrefix($wire, '.'), $explode, $kind, $explode ? '.' : ','),
            ParameterStyle::Matrix => $this->parseMatrix($name, $wire, $explode, $kind),
            ParameterStyle::Form => $this->parseForm($name, $wire, $explode, $kind),
            ParameterStyle::SpaceDelimited => $this->parseDelimitedQuery($name, $wire, self::SPACE_DELIMITERS, $kind),
            ParameterStyle::PipeDelimited => $this->parseDelimitedQuery($name, $wire, self::PIPE_DELIMITERS, $kind),
            ParameterStyle::DeepObject => $this->parseDeepObject($name, $wire),
        };
    }

    /** @param string|list<string>|array<string, string> $value */
    private function simple(string|array $value, bool $explode, string $pairSeparator): string
    {
        if (is_string($value)) {
            return $this->encode($value);
        }
        if (array_is_list($value)) {
            return implode($pairSeparator, array_map($this->encode(...), $this->asList($value)));
        }

        $value = $this->asObject($value);
        $parts = [];
        foreach ($value as $key => $item) {
            $parts[] = $this->encode($key) . ($explode ? '=' : ',') . $this->encode($item);
        }

        return implode($explode ? $pairSeparator : ',', $parts);
    }

    /** @param string|list<string>|array<string, string> $value */
    private function matrix(string $name, string|array $value, bool $explode): string
    {
        if (is_string($value)) {
            return ';' . $this->encode($name) . '=' . $this->encode($value);
        }
        if (array_is_list($value)) {
            if (!$explode) {
                return ';' . $this->encode($name) . '=' . implode(',', array_map($this->encode(...), $value));
            }

            return implode('', array_map(
                fn(string $item): string => ';' . $this->encode($name) . '=' . $this->encode($item),
                $value,
            ));
        }

        if (!$explode) {
            return ';' . $this->encode($name) . '=' . $this->simple($this->asObject($value), explode: false, pairSeparator: ',');
        }

        $parts = [];
        foreach ($this->asObject($value) as $key => $item) {
            $parts[] = ';' . $this->encode($key) . '=' . $this->encode($item);
        }

        return implode('', $parts);
    }

    /** @param string|list<string>|array<string, string> $value */
    private function form(string $name, string|array $value, bool $explode): string
    {
        if (is_string($value)) {
            return $this->pair($name, $value);
        }
        if (array_is_list($value)) {
            $items = $this->asList($value);
            if (!$explode) {
                return $this->pair($name, implode(',', array_map($this->encode(...), $items)), encoded: true);
            }

            return implode('&', array_map(fn(string $item): string => $this->pair($name, $item), $items));
        }
        if ($explode) {
            $parts = [];
            foreach ($this->asObject($value) as $key => $item) {
                $parts[] = $this->pair($key, $item);
            }

            return implode('&', $parts);
        }

        return $this->pair($name, $this->simple($this->asObject($value), explode: false, pairSeparator: ','), encoded: true);
    }

    /**
     * @param list<string> $value
     * @param non-empty-string $delimiter the separator as the value reads it
     * @param non-empty-string $wireDelimiter the separator as it goes on the wire
     */
    private function delimitedQuery(string $name, array $value, string $delimiter, string $wireDelimiter): string
    {
        if (!array_is_list($value)) {
            throw new \InvalidArgumentException('Delimited query parameters require an array value');
        }
        foreach ($value as $item) {
            // The style has no escape for its own separator: an item carrying
            // one would come back as two on parse.
            if (str_contains($item, $delimiter)) {
                throw new \InvalidArgumentException(sprintf('Delimited query parameter values cannot contain "%s"', $delimiter));
            }
        }

        return $this->pair($name, implode($wireDelimiter, array_map($this->encode(...), $value)), encoded: true);
    }

    /** @param array<string, string> $value */
    private function queryObject(string $name, array $value): string
    {
        return implode('&', array_map(
            fn(string $key, string $item): string => $this->pair($name . '[' . $key . ']', $item),
            array_keys($value),
            array_values($value),
        ));
    }

    private function pair(string $name, string $value, bool $encoded = false): string
    {
        return $this->encode($name) . '=' . ($encoded ? $value : $this->encode($value));
    }

    private function encode(string $value): string
    {
        return $this->percentEncoded ? rawurlencode($value) : $value;
    }

    private function decode(string $value): string
    {
        return $this->percentEncoded ? rawurldecode($value) : $value;
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private function decodeList(array $values): array
    {
        $result = [];
        foreach ($values as $value) {
            $result[] = $this->decode($value);
        }

        return $result;
    }

    /** @return list<string> */
    private function asList(string|array $value): array
    {
        if (is_string($value) || !array_is_list($value)) {
            throw new \InvalidArgumentException('Parameter requires a list value');
        }

        foreach ($value as $item) {
            if (!is_string($item)) {
                throw new \InvalidArgumentException('Parameter values must be strings');
            }
        }

        /** @var list<string> $value */
        return $value;
    }

    /** @return array<string, string> */
    private function asObject(string|array $value): array
    {
        if (is_string($value) || array_is_list($value)) {
            throw new \InvalidArgumentException('Parameter requires an object value');
        }

        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key) || !is_string($item)) {
                throw new \InvalidArgumentException('Parameter object keys and values must be strings');
            }
            $result[$key] = $item;
        }

        return $result;
    }

    private function withoutPrefix(string $wire, string $prefix): string
    {
        if (!str_starts_with($wire, $prefix)) {
            throw new \InvalidArgumentException(sprintf('Expected parameter fragment prefixed with "%s"', $prefix));
        }

        return substr($wire, strlen($prefix));
    }

    /**
     * @param non-empty-string $pairSeparator
     * @return string|list<string>|array<string, string>
     */
    private function parseSimple(string $wire, bool $explode, ParameterKind $kind, string $pairSeparator): string|array
    {
        if ($kind === ParameterKind::Scalar) {
            return $this->decode($wire);
        }
        if ($kind === ParameterKind::List) {
            return $this->decodeList($wire === '' ? [] : explode($pairSeparator, $wire));
        }

        if (!$explode) {
            return $this->parseUnexplodedObject($wire);
        }

        $parts = $wire === '' ? [] : explode($pairSeparator, $wire);
        /** @var array<string, string> $result */
        $result = [];
        foreach ($parts as $part) {
            [$key, $value] = $this->splitPair($part, $explode);
            $result[$this->decode($key)] = $this->decode($value);
        }

        return $result;
    }

    /** @return array<string, string> */
    private function parseUnexplodedObject(string $wire): array
    {
        if ($wire === '') {
            return [];
        }

        $pieces = explode(',', $wire);
        if (count($pieces) % 2 !== 0) {
            throw new \InvalidArgumentException('Serialized object parameter contains an incomplete pair');
        }

        /** @var array<string, string> $result */
        $result = [];
        $counter = count($pieces);
        for ($index = 0; $index < $counter; $index += 2) {
            $result[$this->decode($pieces[$index])] = $this->decode($pieces[$index + 1]);
        }

        return $result;
    }

    /** @return string|list<string>|array<string, string> */
    private function parseMatrix(string $name, string $wire, bool $explode, ParameterKind $kind): string|array
    {
        $parts = array_values(array_filter(explode(';', ltrim($wire, ';')), static fn(string $part): bool => $part !== ''));
        if ($kind === ParameterKind::Scalar) {
            return $this->decode($this->valueForName($name, $parts));
        }
        if ($kind === ParameterKind::List) {
            if ($explode) {
                return $this->decodeList($this->valuesForName($name, $parts));
            }

            return $this->decodeList(explode(',', $this->valueForName($name, $parts)));
        }

        if (!$explode) {
            return $this->parseSimple($this->valueForName($name, $parts), explode: false, kind: ParameterKind::Object, pairSeparator: ',');
        }

        /** @var array<string, string> $result */
        $result = [];
        foreach ($parts as $part) {
            [$key, $value] = $this->splitPair($part, equals: true);
            $result[$this->decode($key)] = $this->decode($value);
        }

        return $result;
    }

    /** @return string|list<string>|array<string, string> */
    private function parseForm(string $name, string $wire, bool $explode, ParameterKind $kind): string|array
    {
        return $this->parsePairs($name, $wire, $explode, $kind, '&');
    }

    /**
     * @param non-empty-list<non-empty-string> $delimiters every wire form of the
     *        separator; the first is the canonical one
     * @return list<string>
     */
    private function parseDelimitedQuery(string $name, string $wire, array $delimiters, ParameterKind $kind): array
    {
        if ($kind !== ParameterKind::List) {
            throw new \InvalidArgumentException('Delimited query parameters require a list shape');
        }

        $value = $this->valueForName($name, explode('&', $wire));
        $canonical = $delimiters[0];
        // Split before decoding, so a percent-encoded item never turns into a
        // separator; folding the alternatives first keeps that single pass.
        $value = str_replace($delimiters, $canonical, $value);

        return $this->decodeList(explode($canonical, $value));
    }

    /** @return array<string, string> */
    private function parseDeepObject(string $name, string $wire): array
    {
        /** @var array<string, string> $result */
        $result = [];
        foreach (explode('&', $wire) as $part) {
            [$key, $value] = $this->namedPair($part);
            $prefix = $this->encode($name) . '%5B';
            if (!str_starts_with($key, $prefix) || !str_ends_with($key, '%5D')) {
                throw new \InvalidArgumentException('Invalid deepObject parameter');
            }
            $result[$this->decode(substr($key, strlen($prefix), -3))] = $this->decode($value);
        }

        return $result;
    }

    /**
     * @param non-empty-string $separator
     * @return string|list<string>|array<string, string>
     */
    private function parsePairs(string $name, string $wire, bool $explode, ParameterKind $kind, string $separator): string|array
    {
        $parts = explode($separator, $wire);
        if ($kind === ParameterKind::Scalar) {
            return $this->decode($this->valueForName($name, $parts));
        }
        if ($kind === ParameterKind::List) {
            if ($explode) {
                return $this->decodeList($this->valuesForName($name, $parts));
            }

            return $this->decodeList(explode(',', $this->valueForName($name, $parts)));
        }
        if (!$explode) {
            return $this->parseSimple($this->valueForName($name, $parts), explode: false, kind: ParameterKind::Object, pairSeparator: ',');
        }

        /** @var array<string, string> $result */
        $result = [];
        foreach ($parts as $part) {
            [$key, $value] = $this->namedPair($part);
            $result[$this->decode($key)] = $this->decode($value);
        }

        return $result;
    }

    /**
     * The single value a name carries, for the styles that admit exactly one.
     *
     * A repeated name is refused rather than resolved. Taking the first match
     * — what this did — let a request satisfy the contract with one value and
     * hand the application another: PHP reads the last occurrence in
     * `parse_str()`, `$_GET` and therefore in every PSR-7
     * `getQueryParams()`, so `?n=5&n=999` validated `5` and the application
     * acted on `999`. Taking the last instead would agree with PHP and with
     * nothing else; the request itself is ambiguous, and that is what the
     * violation says.
     *
     * @param list<string> $parts
     */
    private function valueForName(string $name, array $parts): string
    {
        $values = $this->allValuesForName($name, $parts);
        if (isset($values[1])) {
            throw new DuplicateParameterValue(sprintf('Parameter "%s" occurs more than once', $name));
        }
        if ($values === []) {
            throw new \InvalidArgumentException(sprintf('Parameter "%s" is missing from serialized value', $name));
        }

        return $values[0];
    }

    /**
     * @param list<string> $parts
     * @return list<string>
     */
    private function valuesForName(string $name, array $parts): array
    {
        $values = $this->allValuesForName($name, $parts);
        if ($values === []) {
            throw new \InvalidArgumentException(sprintf('Parameter "%s" is missing from serialized value', $name));
        }

        return $values;
    }

    /**
     * @param list<string> $parts
     * @return list<string>
     */
    private function allValuesForName(string $name, array $parts): array
    {
        $encodedName = $this->encode($name);
        $values = [];
        foreach ($parts as $part) {
            [$key, $value] = $this->namedPair($part);
            if ($key === $encodedName) {
                $values[] = $value;
            }
        }

        return $values;
    }

    /**
     * One `name=value` pair of a query or cookie string, where a name with no
     * `=` carries the empty value — the reading `parse_str()` and every SAPI
     * apply, so the validator sees what the application will.
     *
     * This is deliberately more forgiving than {@see splitPair()}, which stays
     * strict for the styles that have no valueless form. The pairs read here
     * are not all ours: an exploded object parameter is handed every pair a
     * sibling parameter does not claim, so one stray `&flag` in the request
     * used to fail an unrelated parameter's deserialization.
     *
     * @return array{string, string}
     */
    private function namedPair(string $part): array
    {
        [$key, $value] = array_pad(explode('=', $part, 2), 2, '');

        return [$key, $value];
    }

    /** @return array{string, string} */
    private function splitPair(string $part, bool $equals): array
    {
        $separator = $equals ? '=' : ',';
        $pieces = explode($separator, $part, 2);
        if (count($pieces) !== 2) {
            throw new \InvalidArgumentException('Serialized object parameter contains an incomplete pair');
        }

        return [$pieces[0], $pieces[1]];
    }
}
