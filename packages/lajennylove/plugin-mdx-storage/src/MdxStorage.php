<?php

namespace Lajennylove\MdxStorage;

/**
 * Bridge functions: MdxStorage.PreferredLanguage, MdxStorage.CachesPath, MdxStorage.ExcludeFromBackup.
 * The app calls them through App\Storage\NativeStorageBridge, which falls back when they're missing (Jump).
 */
class MdxStorage {}
