<?php

namespace NiBurkin\STT;

/**
 * Class Convert
 * @package NiBurkin\STT
 */
class Convert
{
    /** @var string */
    protected $output = "";

    /**
     * @param string $swaggerPath
     */
    public function convert(string $swaggerPath): string
    {
        $yaml = yaml_parse_file(
            $swaggerPath
        );

        $schemas = [];
        $this->walkSchemaRecursive($yaml["components"]["schemas"], $schemas);
        $this->output = "";

        $list = array_merge(
            $this->getSchemaList($schemas),
            $this->getSchemasFromPaths($yaml["paths"], $yaml["components"]["parameters"] ?? [])
        );

        $this->output = $this->makeInterfaces($list);

        $this->output .= $this->walkOnPaths($yaml["paths"], $schemas);

        return $this->output;
    }

    private function getSchemasFromPaths(array &$paths, array $base_parameters)
    {
        $list = [];
        foreach ($paths as $path => &$methods) {
            foreach ($methods as $method => &$info) {
                if (isset($info["parameters"])) {
                    $entity = [];
                    foreach ($info["parameters"] as $parameter) {
                        if (isset($parameter['$ref'])) {
                            $parameter = $base_parameters[basename($parameter['$ref'])];
                        }

                        if (isset($parameter["in"]) && $parameter["in"] == "query") {
                            $entity[$parameter["name"]] = $parameter["schema"];
                            if (!isset($parameter["schema"]["nullable"]) && isset($parameter["required"])) {
                                $entity[$parameter["name"]]["nullable"] = !$parameter["required"];
                            }
                        }
                    }
                    $name = $this->getNameFromPath($path);

                    if (!empty($entity)) {
                        $name = $name . ucfirst($method) . "Params";
                        $list[$name] = $entity;

                        $info["parameters_interface"] = $name;
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
            $list[$name] = $properties;
        }

        return $list;
    }

    /**
     * @param array $list
     * @return string
     */
    private function makeInterfaces(array $list): string
    {
        $output = "";
        foreach ($list as $name => $properties) {
            $output .= "export interface {$name} {\n";
            foreach ($properties as $property => $data) {
                if (isset($data["type"])) {
                    $type = $data["type"];
                    switch ($type) {
                        default:
                            $type = $this->getBaseType($type, $data);
                            break;
                    }

                    $property = lcfirst(ucwords($property, "_"));
                    $property = str_replace("_", "", $property);
                    if (isset($data["nullable"]) && $data["nullable"]) {
                        $property .= "?";
                    }
                    $output .= "\t{$property}: {$type}, \n";
                }
            }
            $output .= "}\n\n";
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
            $quotes = "'";
            if (preg_match("/\{(.+?)\}/ui", $_path)) {
                $quotes = "`";
            }

            foreach ($methods as $method => $item) {
                $options = $fields = [];
                if (isset($item["parameters"])) {
                    foreach ($item["parameters"] as $param) {
                        if (isset($param["required"]) && $param["required"]) {
                            if (!isset($param["schema"]["type"])) {
                                $param["schema"]["type"] = "";
                            }
                            $type = $this->getBaseType($param["schema"]["type"], $param["schema"]);
                            if (empty($type)) {
                                $type = "any";
                            }

                            $fields[] = "{$param["name"]}: {$type}";
                        }
                    }
                }

                $success = $item["responses"][200];

                $responseType = "any";

                if (isset($success["content"])) {
                    $success = $success["content"]["application/json"]["schema"];
                    if (isset($success['$ref'])) {
                        $responseType = basename($success['$ref']);
                        if (isset($schemas[$responseType])) {
                            if ($schemas[$responseType]["type"] == "array" && $schemas[$responseType]["child"]) {
                                $responseType = $schemas[$responseType]['child'] . '[]';
                            }
                        }
                    } else if (isset($success["type"]) && $success["type"] == "array") {
                        $responseType = basename($success['items']['$ref']) . '[]';
                    }
                }

                if (isset ($item["requestBody"])) {
                    $body = $item["requestBody"]["content"];
                    if (isset($body["application/json"])) {
                        $body = $body["application/json"]["schema"];
                        if (isset($body['$ref'])) {
                            $fields[] = "body: " . basename($body['$ref']);
                            $options[] = "json: body";
                        }
                    } else if (isset($body["multipart/form-data"])) {
                        $body = $body["multipart/form-data"]["schema"];
                        if (isset($body['$ref'])) {
                            $fields[] = "body: " . basename($body['$ref']);
                            $options[] = "body";
                        } else {
                            $fields[] = "body: FormData";
                            $options[] = "body";
                        }
                    }
                }
                $options[] = "method: '" . strtoupper($method) . "'";


                if (isset($item["parameters_interface"])) {
                    $fields[] = "params: " . $item["parameters_interface"];
                    $options[] = "params";
                }

                $options = implode(", ", $options);

                $fields[] = "options?: any";

                $fields = implode(", ", $fields);

                $_name = $name . ucfirst($method) . "Request";

                $output .= "export const {$_name} = ({$fields}) => request<{$responseType}>({$quotes}{$url}{$quotes}, { {$options}, ...options })\n\n";
            }
        }

        return $output;
    }

    /**
     * @param string $path
     * @return string
     */
    protected function getNameFromPath(string $path): string
    {
        $name = ucwords($path, "/-{");
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
                fn($type) => $this->getBaseType($type),
                array_column($data["oneOf"], "type")
            );

            return implode("|", $types);
        }

        switch ($type) {
            case "string":
                if (isset($data["format"]) && $data["format"] == "binary") {
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
                return "{$array_type}[]";
            case "integer":
                return "number";
            default:
                return $type;
        }
    }

    /**
     * @param array $schemas
     * @param $output
     */
    protected function walkSchemaRecursive($schemas, &$output)
    {
        foreach ($schemas as $name => $schema) {
            $allOf = false;
            if (isset($schema["allOf"])) {
                $allOf = true;
                $properties = $schema["properties"] ?? [];
                $required = [];
                foreach ($schema["allOf"] as $info) {
                    if (isset($info['$ref'])) {
                        $basename = basename($info['$ref']);
                        $properties = array_merge($properties, $schemas[$basename]["properties"] ?? []);
                    }
                    if (isset($info['required'])) {
                        $required = array_merge($required, $info['required']);
                    }
                }
                foreach ($properties as $field => $property) {
                    if (in_array($name, $required)) {
                        unset($property["nullable"]);
                        $property["required"] = true;
                    }

                    $properties[$field] = $property;
                }

                $schema = [
                    "type" => "object",
                    "properties" => $properties
                ];

            }

            $type = $schema["type"] ?? "object";
            $properties = [];
            $child = null;

            if ($type == "array") {
                $child = $name . "Items";
                $this->walkSchemaRecursive([
                    $child => $schema["items"]
                ], $output);
            } else {
                foreach ($schema["properties"] ?? [] as $property => $data) {
                    if (isset($data["oneOf"])) {
                        $types = array_map(
                            fn($type) => $this->getBaseType($type),
                            array_column($data["oneOf"], "type")
                        );

                        $data["type"] = implode("|", $types);
                    }
                    if (isset($data["type"])) {
                        $pname = ucwords($property, "_");
                        $pname = str_replace("_", "", $pname);
                        $subname = $name . $pname;
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
                                if (isset($data["items"]["type"]) && $data["items"]["type"] == "object") {
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
            $output[$name] = ["type" => $type, "properties" => $properties, "child" => $child];
        }
    }
}