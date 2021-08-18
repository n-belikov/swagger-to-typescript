<?php

namespace NiBurkin\STT;

use Illuminate\Support\Str;

/**
 * Class Convert
 * @package NiBurkin\STT
 */
class Convert
{
    /** @var string */
    protected $output = "";

    protected $keySchema = [
        "requestBodies" => "requestBody",
        "responses" => "responses",
        "schemas" => "schemas"
    ];

    /**
     * @param string $swaggerPath
     */
    public function convert(string $swaggerPath): string
    {
        // Parse yaml file
        $yaml = yaml_parse_file(
            $swaggerPath
        );

        $schemas = [];

        $this->walkOnSchemasPrepareBody($yaml["components"], $yaml["paths"]);
        $this->preaparePaths($yaml["paths"]);

        $this->walkSchemaRecursive($yaml["components"]["schemas"], $schemas);
        $this->output = "";

        $list = array_merge(
        // Map schemas for normal view
            $this->getSchemaList($schemas),

            // Map paths and add parameters in own interfaces
            $this->getSchemasFromPaths($yaml["paths"], $yaml["components"]["parameters"] ?? [])
        );

        // Make TypeScript interfaces
        $this->output = $this->makeInterfaces($list);

        // Walk on paths to make url requests
        $this->output .= $this->walkOnPaths($yaml["paths"], $schemas);

        return $this->output;
    }

    /**
     * @param array $paths
     */
    protected function preaparePaths(array &$paths)
    {
        foreach ($paths as $path => &$methods) {
            $parameters = [];
            if (isset($methods["parameters"])) {
                $parameters = $methods["parameters"];
                unset($methods["parameters"]);
            }
            foreach ($methods as $method => &$schema) {
                if (count($parameters)) {
                    $schema["parameters"] = array_merge($parameters, $schema["parameters"] ?? []);
                }
            }
        }
    }

    /**
     * @param array $array
     * @param string $key
     */
    private function walkOnArray(array $array, string $key)
    {
        $value = $array;
        foreach (explode(".", $key) as $keyNext) {
            if ($keyNext === '*') {
                $value = Arr::first($value ?? []);
            } else {
                $value = $value[$keyNext] ?? null;
            }
        }
        return $value;
    }

    /**
     * @param $components
     * @param $paths
     */
    private function walkOnSchemasPrepareBody(array &$components, array &$paths): void
    {
        $results = $this->prepareComponentSchema($components);

        foreach ($paths as $path => &$methods) {
            foreach ($methods as $method => &$content) {
                if (isset($content['responses'])) {
                    foreach ($content['responses'] as $key => &$response) {
                        if (isset($response['$ref'])) {
                            $responseName = basename($response['$ref']);
                            $response = $results["responses"][$responseName];
                        }
                    }
                    unset($response);
                }
                if (isset($content['requestBody'], $content['requestBody']['$ref'])) {
                    $bodyName = basename($content['requestBody']['$ref']);

                    $body = $results["requestBody"][$bodyName];

                    $result = $this->walkOnArray($body, "content.*.schema");

                    $result["type"] = $result["type"] ?? "object";

                    if ($result["type"] === "array") {

                        if (!isset($result["items"]['$ref'])) {
                            $results["schemas"][$bodyName] = $result["items"];

                            foreach ($body["content"] as &$data) {
                                $data["schema"] = [
                                    "type" => "array",
                                    "items" => [
                                        '$ref' => '#/components/schemas/' . $bodyName
                                    ]
                                ];
                            }
                            unset($data);
                        } else {
                            $bodyName = basename($result["items"]['$ref']);

                            foreach ($body["content"] as &$data) {
                                $data["schema"] = [
                                    'type' => 'array',
                                    'items' => [
                                        '$ref' => '#/components/schemas/' . $bodyName
                                    ]
                                ];
                            }
                            unset($data);
                        }
                    } else {
                        $results["schemas"][$bodyName] = $result;

                        foreach ($body["content"] as &$data) {
                            $data["schema"] = [
                                '$ref' => '#/components/schemas/' . $bodyName
                            ];
                        }
                        unset($data);
                    }
                    $content["requestBody"] = $body;
                }

            }
            unset($content);
        }
        unset($methods);

        foreach ($results["schemas"] ?? [] as $name => $schema) {
            $components["schemas"][$name] = $schema;
        }
    }

    /**
     * @param array $components
     * @return array
     */
    private function prepareComponentSchema(array $components): array
    {
        $result = [];
        foreach ($components as $key => $componentList) {
            if (isset($this->keySchema[$key]) && $key = $this->keySchema[$key]) {
                foreach ($componentList as $name => $content) {
                    $result[$key][$name] = $content;
                }
            }
        }

        return $result;
    }

    /**
     * @param array $parameters
     * @return array
     */
    private function flattenParameters(array $parameters, array $parent = null): array
    {
        $flatten = [];
        foreach ($parameters as $name => $parameter) {
            if (isset($parameter["schema"], $parameter["schema"]["properties"]) && (!isset($parameter["style"]) || $parameter["style"] !== "deepObject")) {
                $flatten = array_merge($flatten, $this->flattenParameters($parameter["schema"]["properties"], $parameter));
            } else {
                if (!isset($parameter["name"]) && !(count($parameter) === 1 && isset($parameter['$ref']))) {
                    $parameter = [
                        "in" => $parent["in"] ?? "query",
                        "required" => $parent["required"] ?? false,
                        "name" => $name,
                        "schema" => $parameter
                    ];
                }
                $flatten[] = $parameter;
            }
        }
        return $flatten;
    }

    /**
     * @param array $paths
     * @param array $base_parameters
     * @return array
     */
    private function getSchemasFromPaths(array &$paths, array $base_parameters): array
    {
        $list = [];
        foreach ($paths as $path => &$methods) {
            foreach ($methods as $method => &$info) {
                if (isset($info["parameters"])) {
                    $entity = [];
                    $name = $this->getNameFromPath($path);
                    $name = $name . ucfirst($method) . "Params";
                    $required = [];
                    $info["parameters"] = $this->flattenParameters($info["parameters"]);
                    foreach ($info["parameters"] as $parameter) {
                        if (isset($parameter["schema"]) && ($schema = &$parameter["schema"])) {
                            if (isset($schema["properties"])) {
                                $propertyName = $name . Str::studly($parameter["name"]);
                                $list[$propertyName] = [
                                    "properties" => $schema["properties"]
                                ];
                                $schema = [
                                    '$ref' => "#/components/schemas/{$propertyName}"
                                ];

                                unset($schema);

                            }
                        } else if (isset($parameter['$ref'])) {
                            $parameter = $base_parameters[basename($parameter['$ref'])];
                        }


                        if (isset($parameter["in"]) && $parameter["in"] === "query") {
                            $entity[$parameter["name"]] = $parameter["schema"];
                            if (isset($parameter["description"])) {
                                $entity[$parameter["name"]]["description"] = $parameter["description"];
                            }

                            $nullable = true;
                            if (!isset($parameter["schema"]["nullable"]) && isset($parameter["required"])) {
                                $nullable = !$parameter["required"];
                            }
                            if (!$nullable) {
                                $required[] = $parameter["name"];
                            }
                        }
                    }

                    if (!empty($entity)) {
                        $list[$name] = ["properties" => $entity, "required" => $required];

                        $info["parameters_interface"] = [
                            "name" => $name,
                            "required" => count($required) > 0 ? true : false
                        ];
                    }
                }
            }
        }

        return $list;
    }

    /**
     * @param array $schemas
     */
    private function getSchemaList(array $schemas)
    {
        $list = [];
        foreach ($schemas as $name => $schema) {
            $properties = [];

            foreach ($schema["properties"] ?? [] as $property => $data) {
                if (!isset($data["type"])) {
                    if (isset($data["allOf"])) {
                        // TODO: walk on all allOf $ref's
                        $properties[$property] = [
                            "type" => basename($data["allOf"][0]['$ref'])
                        ];
                    }
                } else {
                    switch ($data["type"]) {
                        case "object":
                            $data["type"] = $sub_name = $name . ucfirst($property);
                            $list[$sub_name] = [];
                            break;
                    }
                }
                $properties[$property] = $data;
            }

            $type = $schema["type"];
            $values = [];
            if (isset($schema["enum"])) {
                $type = "enum";
                $values = $schema["enum"];
            }

            $list[$name] = [
                "properties" => $properties,
                "type" => $type,
                "values" => $values,
                "description" => $schema["description"] ?? null,
            ];
            if ($this->hasKey("required", $schema)) {
                $list[$name]["required"] = $schema["required"] ?? null;
            }
        }

        return $list;
    }

    /**
     * @param string $key
     * @param array $array
     * @return bool
     */
    private function hasKey(string $key, array $array): bool
    {
        return in_array($key, array_keys($array));
    }

    /**
     * @param array $list
     * @return string
     */
    private function makeInterfaces(array $list): string
    {
        $output = "";
        $index = 0;
        foreach ($list as $name => $schema) {
            $index++;
            $type = ($schema["type"] ?? "") === "enum" ? "enum" : "interface";

            $required = $this->hasKey("required", $schema) ? ($schema["required"] ?? []) : array_keys($schema["properties"] ?? []);

            $poperty_comments = [];
            $description = $schema["description"] ?? null;
            if (isset($schema["description"]) && !empty($schema["description"])) {
                $description = preg_replace("/\n$/um", "", $description);

                if (preg_match_all("/\*\s{0,}\`(.+?)`[\s\-\:]+(.+?)$/um", $description, $matches)) {
                    $poperty_comments = array_combine($matches[1], $matches[2]);
                    $description = str_replace($matches[0], "", $description);
                    $description = trim($description);
                }

                if (!empty($description)) {
                    if (preg_match("/\n/um", $description)) {
                        $description = preg_replace("/\n\s{1,}/um", "\n", $description);
                        $description = preg_replace("/\n/ui", "\n * ", $description);
                        $output .= "/**\n * {$description}\n */\n";
                    } else {
                        $output .= "// " . $description . "\n";
                    }
                }
            }
            $output .= "export {$type} {$name} {\n";
            foreach ($schema["properties"] as $nameProperty => $data) {
                $type = $data["type"] ?? null;
                $type = $this->getBaseType($type, $data);

                $comment = "";
                if (isset($data["description"])) {
                    $comment = " // {$data["description"]}";
                }
                if (isset($poperty_comments[$nameProperty])) {
                    $comment = " // " . $poperty_comments[$nameProperty];
                }

                $property = lcfirst(ucwords($nameProperty, "_"));
                $property = str_replace("_", "", $property);
                if (!in_array($nameProperty, $required) || (isset($data["required"]) && !$data["required"])) {
                    $property .= "?";
                }
                if (isset($data["nullable"]) && $data["nullable"]) {
                    $type .= "|null";
                }
                $propertyText = "{$property}: {$type},";
                if (!empty($comment)) {
                    $comment = str_replace("\n", " ", $comment);
                }
                $output .= "\t{$propertyText}{$comment}\n";
            }

            if (isset($schema["values"]) && count($schema["values"])) {

                foreach ($schema["values"] as $value) {
                    $comment = null;
                    if (isset($poperty_comments[$value])) {
                        $comment = " // " . $poperty_comments[$value];
                    }
                    $label = strtoupper($value);
                    $label = preg_replace("/([^A-Za-z0-9])/ui", "_", $label);
                    $output .= "\t{$label} = '{$value}',{$comment}\n";
                }
            }

            $output .= "}\n" . ($index < count($list) ? "\n" : "");
        }

        return $output;
    }

    /**
     * @param array $paths
     * @return string
     */
    private function walkOnPaths(array $paths, array $schemas): string
    {
        $output = "";

        foreach ($paths as $_path => $methods) {
            $name = $this->getNameFromPath($_path);
            $url = preg_replace("/\{(.+?)\}/ui", '${\\1}', $_path);

            if (preg_match_all("/{(.+?)}/", $url, $matches)) {
                foreach ($matches[0] as $index => $original) {
                    $value = preg_replace("/([^A-Za-z0-9]+)/", "", ucwords($matches[1][$index], "_-"));
                    $value = lcfirst($value);

                    $url = str_replace($original, '{' . $value . '}', $url);
                }
            }

            $quotes = "'";
            if (preg_match("/\{(.+?)\}/ui", $_path)) {
                $quotes = "`";
            }

            foreach ($methods as $method => $item) {
                $options = $fields = [];
                if (isset($item["parameters"])) {
                    foreach ($item["parameters"] as $param) {
                        if (isset($param["in"]) && $param["in"] === "path") {
                            if (!isset($param["schema"]["type"])) {
                                $param["schema"]["type"] = "";
                            }
                            $type = $this->getBaseType($param["schema"]["type"], $param["schema"]);
                            if (empty($type)) {
                                $type = "any";
                            }

                            $param["name"] = Str::camel($param["name"]);

                            $fields[] = "{$param["name"]}: {$type}";
                        }
                    }
                }

                $success = null;

                foreach ($item["responses"] as $code => $result) {
                    if ($code >= 200 && $code < 300) {
                        $success = $result;
                        break;
                    }
                }

                $responseType = "any";

                if ($success && isset($success["content"])) {
                    $success = $success["content"]["application/json"]["schema"];
                    if (isset($success['$ref'])) {
                        $responseType = basename($success['$ref']);
                        if (isset($schemas[$responseType])) {
                            $ref = $schemas[$responseType];
                            if ($ref["type"] === "array" && $ref["child"]) {
                                $responseType = $ref['child'] . '[]';
                            } else if ($ref["type"] === "array" && isset($ref["items"])) {
                                if (!isset($ref["items"]['$ref']) && isset($ref["items"]["type"])) {
                                    $type = $this->getBaseType($ref["items"]["type"], $ref);
                                    $responseType = $type . "[]";
                                } else {
                                    $ref = basename($ref['$ref']);
                                    $responseType = $ref . '[]';
                                }
                            }
                        }
                    } else if (isset($success["type"]) && $success["type"] === "array") {
                        $responseType = basename($success['items']['$ref']) . '[]';
                    } else if (isset($success["type"])) {
                        $responseType = $this->getBaseType($success["type"], $success);
                    }
                }

                if (isset ($item["requestBody"]) && ($requestBody = $item["requestBody"])) {
                    $required = !($requestBody["required"] ?? true);
                    $body = $requestBody["content"];
                    if (isset($body["application/json"])) {
                        $body = $body["application/json"]["schema"];
                        $this->mapBodySchema($body, $required, $fields, $options);
                    } else if (isset($body["multipart/form-data"])) {
                        $body = $body["multipart/form-data"]["schema"];
                        $this->mapBodySchema($body, $required, $fields, $options);
                    }
                }

                $options[] = sprintf("method: '%s'", strtoupper($method));


                if (isset($item["parameters_interface"])) {
                    $required = $item["parameters_interface"]["required"];
                    $required = !$required ? "?" : "";
                    $fields[] = "params{$required}: " . $item["parameters_interface"]["name"];
                    $options[] = "params";
                }

                $options = implode(", ", $options);

                $fields[] = "options?: any";

                $fields = implode(", ", $fields);

                $_name = $item["operationId"] ?? ($name . ucfirst(strtolower($method)) . "Request");

                $summary = "";
                if (isset($item["summary"])) {
                    $summary = "\n// {$item["summary"]}";
                }

                $output .= sprintf(
                    "%s\nexport const %s = (%s) => request<%s>(%s, { %s, ...options })\n",
                    $summary,
                    $_name,
                    $fields,
                    $responseType,
                    $quotes . $url . $quotes,
                    $options
                );
            }
        }

        return $output;
    }

    /**
     * @param array $schema
     * @param array $fields
     * @param array $options
     */
    protected function mapBodySchema(array $schema, bool $isRequired, array &$fields, array &$options): void
    {
        $type = $schema["type"] ?? "object";
        $required = $isRequired ? "?" : "";
        $prefix = "body{$required}: ";
        if ($type === "array") {
            $fields[] = $prefix . basename($schema["items"]['$ref']) . '[]';
        } else if (isset($schema['$ref'])) {
            $fields[] = $prefix . basename($schema['$ref']);
        } else {
            $type = $this->getBaseType($schema["type"] ?? "object", $schema);
            $fields[] = $prefix . $type;
        }
        $options[] = "body";
    }

    /**
     * @param string $path
     * @return string
     */
    protected function getNameFromPath(string $path): string
    {
        $name = ucwords($path, "/-_{");
        $name = preg_replace("/([^A-Za-z0-9]+)/", "", $name);

        return $name;
    }

    /**
     * @param $type
     * @param array $data
     * @return string
     */
    protected function getBaseType($type, array $data = []): string
    {
        if (isset($data["oneOf"])) {
            $types = array_map(
                fn($item) => $this->getBaseType($item["type"], $item),
                $data["oneOf"]
            );
            return implode("|", $types);
        }
        if (isset($data['$ref'])) {
            return basename($data['$ref']);
        }

        switch ($type) {
            case null:
                return "any";
            case "string":
                if (isset($data["format"]) && $data["format"] === "binary") {
                    return "Blob";
                }
                return "string";
            case "array":
                $array_type = "";
                if (isset($data["items"]['$ref'])) {
                    $array_type = basename($data["items"]['$ref']);
                } else if (isset($data["items"]["type"])) {
                    $array_type = $this->getBaseType($data["items"]["type"], $data);
                }
                if (isset($data['items']['oneOf'])) {
                    return "Array<{$array_type}>";
                }
                return "{$array_type}[]";
            case "integer":
                return "number";
            default:
                if (is_array($type)) {
                    return end($type);
                }
                return $type;
        }
    }

    /**
     * @param array $schemas
     * @param $output
     */
    protected function walkSchemaRecursive(array $schemas, array &$output): void
    {
        foreach ($schemas as $name => $schema) {
            $output = array_merge($output, $this->schemaPrepare($name, $schema, $schemas));
        }
    }

    /**
     * @param string $name
     * @param array $schema
     * @return array
     */
    private function schemaPrepare(string $name, array $schema, array $schemas): array
    {
        $output = [];
        if (isset($schema["allOf"])) {
            $properties = $schema["properties"] ?? [];
            $required = $schema["required"] ?? null;
            foreach ($schema["allOf"] as $info) {
                if (isset($info['$ref'])) {
                    $basename = basename($info['$ref']);
                    $properties = array_merge($schemas[$basename]["properties"] ?? [], $properties);
                    if (isset($schemas[$basename]["required"]) && count($schemas[$basename]["required"]) > 0) {
                        $required = array_merge($required ?? [], $schemas[$basename]["required"] ?? []);
                    }
                }
                if (isset($info['required'])) {
                    $required = array_merge($required, $info['required']);
                }
            }
            foreach ($properties as $field => $property) {
                if (in_array($name, $required ?? [])) {
                    unset($property["nullable"]);
                    $property["required"] = true;
                }

                $properties[$field] = $property;
            }

            $schema = [
                "type" => "object",
                "properties" => $properties
            ];
            if ($this->hasKey("required", $schema) || $required !== null) {
                $schema["required"] = $required;
            }
        }

        $type = $schema["type"] ?? "object";
        $properties = [];
        $child = null;

        if ($type === "array") {
            $child = $name . "Items";
            $this->walkSchemaRecursive([
                $child => $schema["items"]
            ], $output);
        } else {
            foreach ($schema["properties"] ?? [] as $property => $data) {
                if (isset($data["allOf"])) {
                    $allOf = [];
                    foreach ($data["allOf"] as $items) {
                        foreach ($items as $key => $value) {
                            $allOf[$key][] = $value;
                        }
                    }
                    if (isset($allOf['$ref']) && count($allOf['$ref']) > 1) {
                        $refInterface = [];
                        foreach ($allOf['$ref'] as $ref) {
                            $basename = basename($ref);
                            if (!isset($schemas[$basename])) {
                                throw new \InvalidArgumentException('$ref ' . $ref . ' not found');
                            }
                            $refInterface = array_merge_recursive($refInterface, $schemas[$basename]);
                        }

                        $subName = Str::studly("{$name} {$property}");
                        $allOf['$ref'] = [
                            "#/components/schemas/{$subName}"
                        ];
                        $output = array_merge($output, $this->schemaPrepare($subName, $refInterface, $schemas));
                    }
                    if (isset($allOf["nullable"])) {
                        $data["nullable"] = end($allOf["nullable"]);
                    }
                    if (isset($allOf['$ref'])) {
                        $data['$ref'] = end($allOf['$ref']);
                    }

                    unset($data['allOf']);
                }

                if (isset($data["oneOf"])) {
                    $types = array_map(
                        fn($type) => $this->getBaseType($type),
                        array_column($data["oneOf"], "type")
                    );

                    $data["type"] = implode("|", $types);
                }
                if (isset($data["type"])) {
                    $subname = $name . Str::studly($property);
                    switch ($data["type"]) {
                        case "object":
                            if (isset($data['$ref'])) {
                                $data["type"] = basename($data['$ref']);
                            } else {
                                $this->walkSchemaRecursive([
                                    $subname => $data
                                ], $output);
                                $data["type"] = $subname;
                            }
                            break;
                        case "array":
                            if (isset($data['items']['oneOf'])) {
                                $type = $this->getBaseType($data["items"]["type"] ?? "object", $data["items"]);
                                $data["items"]["type"] = $type;
                            } else if (isset($data["items"]["type"]) && $data["items"]["type"] === "object") {
                                $this->walkSchemaRecursive([
                                    $subname => $data["items"]
                                ], $output);
                                $data["items"]["type"] = $subname;
                            }
                            break;
                    }
                }
                $properties[$property] = $data;
            }
        }

        $output[$name] = array_merge($schema, [
            "type" => $type,
            "properties" => $properties,
            "child" => $child
        ]);
        return $output;
    }
}