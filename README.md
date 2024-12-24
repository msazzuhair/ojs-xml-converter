# OJS XML Converter

This project contains PHP script for converting Open Journal Systems (OJS) exported data between specific versions.

## Progress

| From  | To    | Converters |
| ----- | ----- | ---------- |
| 3.1.0 | 3.3.0 | Issues ✅ |


## Contributing

If you wish to contribute more on this project or create your own converter, you might want to start by creating a new directory structure for conversion between the two versions. Copy the content of `import.php` file and focus on the `DataHandler` class to handle your specific data conversion needs. Interfaces and reusable classes might be provided in the future and any PR for this is welcomed.

You might want to read more about Open Journal System XML specs on their GitHub repo such as [here](https://github.com/pkp/ojs/blob/main/plugins/importexport/native/native.xsd)