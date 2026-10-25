package com.lajennylove.plugins.mdx_storage

import android.content.Context
import com.nativephp.mobile.bridge.BridgeFunction
import com.nativephp.mobile.bridge.BridgeResponse
import java.io.File
import java.util.Locale as JLocale
import java.util.TimeZone as JTimeZone

/**
 * ReaDex storage helpers (Android twin of resources/ios/MdxStorageFunctions.swift).
 *
 * Bridge names are registered through nativephp.json's bridge_functions block as
 * `com.lajennylove.plugins.mdx_storage.MdxStorageFunctions.<Name>` and reach PHP via
 * App\Storage\NativeStorageBridge.
 *
 * Minimum SDK: 24 (configuration.locales.get(0) + Locale.toLanguageTag()).
 */
object MdxStorageFunctions {

    /**
     * BCP-47 language tag of the user's first preferred locale.
     *
     * Mirrors iOS `Locale.preferredLanguages.first` (which reports the phone's language
     * even when the app is only localised in English). On Android the equivalent is the
     * first entry of the configuration locale list.
     */
    class PreferredLanguage(private val context: Context) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val locale = context.resources.configuration.locales.get(0)
                ?: JLocale.getDefault()
            val tag = locale.toLanguageTag().ifBlank { "en" }

            return BridgeResponse.success(mapOf("language" to tag))
        }
    }

    /**
     * IANA identifier for the device's current time zone (e.g. "America/New_York").
     *
     * Named `TimeZone` to match the bridge name declared in nativephp.json; the iOS twin's
     * Swift class happens to be `CurrentTimeZone` because `TimeZone` would collide with the
     * Foundation struct — Kotlin has no such collision since we alias `java.util.TimeZone`.
     */
    class TimeZone : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            return BridgeResponse.success(mapOf("zone" to JTimeZone.getDefault().id))
        }
    }

    /**
     * Absolute path of the app's `cacheDir/readex` directory, created if missing.
     *
     * `context.cacheDir` is the Android equivalent of iOS `~/Library/Caches` — content the
     * OS may evict under storage pressure and that is not backed up by default.
     */
    class CachesPath(private val context: Context) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val dir = File(context.cacheDir, "readex").apply { mkdirs() }

            return BridgeResponse.success(mapOf("path" to dir.absolutePath))
        }
    }

    /**
     * Per-file backup exclusion — iOS-only semantics.
     *
     * Android has no per-file equivalent of `URLResourceValues.isExcludedFromBackup`;
     * opt-out happens app-wide via `android:fullBackupContent="@xml/backup_rules"` in the
     * host app's manifest (see this plugin's resources/android/backup_rules.xml and
     * README.md). The method stays wired so the bridge call succeeds and PHP consumers
     * don't have to branch on platform — it just reports success without touching the file.
     */
    class ExcludeFromBackup : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val path = parameters["path"] as? String
            if (path.isNullOrEmpty()) {
                return BridgeResponse.error(
                    code = "invalid_path",
                    message = "path is required"
                )
            }

            // No-op: backup exclusion is handled app-wide via backup_rules.xml.
            return BridgeResponse.success(mapOf("excluded" to true))
        }
    }
}
