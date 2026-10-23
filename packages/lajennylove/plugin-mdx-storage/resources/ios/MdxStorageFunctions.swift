import Foundation

enum MdxStorageFunctions {

    /// Locale.preferredLanguages, not Locale.current: the latter follows the app's own
    /// localisations and reports en-* on a French or Spanish phone.
    class PreferredLanguage: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            return BridgeResponse.success(data: [
                "language": Locale.preferredLanguages.first ?? "en"
            ])
        }
    }

    class CachesPath: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            let url = FileManager.default.urls(for: .cachesDirectory, in: .userDomainMask)[0]
                .appendingPathComponent("readex", isDirectory: true)
            try FileManager.default.createDirectory(at: url, withIntermediateDirectories: true)
            return BridgeResponse.success(data: ["path": url.path])
        }
    }

    class ExcludeFromBackup: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            guard let path = parameters["path"] as? String, !path.isEmpty else {
                return BridgeResponse.error(code: "invalid_path", message: "path is required")
            }
            var url = URL(fileURLWithPath: path)
            var values = URLResourceValues()
            values.isExcludedFromBackup = true
            try url.setResourceValues(values)
            return BridgeResponse.success(data: ["excluded": true])
        }
    }
}
