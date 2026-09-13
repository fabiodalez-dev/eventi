plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
    id("org.jetbrains.kotlin.plugin.compose")
    id("org.jetbrains.kotlin.plugin.serialization")
}

if (file("google-services.json").exists() || file("src/release/google-services.json").exists()) {
    apply(plugin = "com.google.gms.google-services")
}

kotlin {
    jvmToolchain(21)
    compilerOptions {
        jvmTarget.set(org.jetbrains.kotlin.gradle.dsl.JvmTarget.JVM_21)
    }
}

android {
    val sideloadDebug = providers.gradleProperty("sideloadDebug").orNull == "true"
    val playStore = providers.gradleProperty("playStore").orNull == "true"
    // Exercise the registered Firebase package on an emulator without creating
    // a second Firebase app. Production release builds remain optimized.
    val deviceTests = providers.gradleProperty("deviceTests").orNull == "true"
    check(!playStore || !deviceTests) { "Play publishing cannot use instrumentation-test packaging" }
    testBuildType = if (deviceTests) "release" else "debug"
    namespace = "it.fabiodalez.incitta"
    compileSdk = 36

    defaultConfig {
        applicationId = "it.fabiodalez.incitta"
        minSdk = 26
        targetSdk = 36
        versionCode = 27
        versionName = "1.11.0"

        testInstrumentationRunner = "androidx.test.runner.AndroidJUnitRunner"
        vectorDrawables.useSupportLibrary = true
        buildConfigField(
            "String",
            "API_BASE_URL",
            "\"${providers.gradleProperty("apiBaseUrl").orElse("https://eventi.fabiodalez.it/api/v1/").get()}\"",
        )
    }

    if (playStore) {
        fun signingVariable(name: String): String = providers.environmentVariable(name).orNull
            ?.takeIf { it.isNotBlank() }
            ?: error("Missing Play signing environment variable: $name")
        signingConfigs.create("playUpload") {
            storeFile = file(signingVariable("INCITTA_UPLOAD_KEYSTORE"))
            storePassword = signingVariable("INCITTA_UPLOAD_PASSWORD")
            keyAlias = signingVariable("INCITTA_UPLOAD_ALIAS")
            keyPassword = signingVariable("INCITTA_UPLOAD_PASSWORD")
        }
    }

    buildTypes {
        debug {
            isMinifyEnabled = false
            applicationIdSuffix = if (sideloadDebug) "" else ".debug"
            versionNameSuffix = if (sideloadDebug) "-debug-filtri" else "-debug"
        }
        release {
            isMinifyEnabled = !deviceTests
            isShrinkResources = !deviceTests
            // Preserve sideload updates; Play bundles use a separate private key.
            signingConfig = signingConfigs.getByName(if (playStore) "playUpload" else "debug")
            proguardFiles(
                getDefaultProguardFile("proguard-android-optimize.txt"),
                "proguard-rules.pro",
            )
        }
    }

    tasks.matching { it.name == "bundleRelease" }.configureEach {
        doFirst {
            check(playStore) { "Play bundle requires -PplayStore=true and private upload credentials" }
        }
    }

    buildFeatures {
        compose = true
        buildConfig = true
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_21
        targetCompatibility = JavaVersion.VERSION_21
    }

    packaging.resources.excludes += setOf(
        "/META-INF/{AL2.0,LGPL2.1}",
        "/META-INF/DEPENDENCIES",
    )
}

val generateArchivoFont by tasks.registering(Copy::class) {
    from(rootProject.file("../resources/fonts/og-title.ttf"))
    into(layout.buildDirectory.dir("generated/incittaRes/font"))
    rename { "archivo_semibold.ttf" }
}

android.sourceSets.getByName("main").res.srcDir(layout.buildDirectory.dir("generated/incittaRes"))
tasks.named("preBuild").configure { dependsOn(generateArchivoFont) }

dependencies {
    implementation(platform("com.google.firebase:firebase-bom:34.19.0"))
    implementation("com.google.firebase:firebase-messaging")
    val composeBom = platform("androidx.compose:compose-bom:2026.09.00")
    implementation(composeBom)
    androidTestImplementation(composeBom)

    implementation("androidx.activity:activity-compose:1.12.3")
    implementation("androidx.core:core-ktx:1.19.0")
    implementation("androidx.compose.material3:material3")
    implementation("androidx.compose.material:material-icons-extended")
    implementation("androidx.compose.ui:ui")
    implementation("androidx.compose.ui:ui-tooling-preview")
    implementation("androidx.lifecycle:lifecycle-runtime-compose:2.10.0")
    implementation("androidx.lifecycle:lifecycle-viewmodel-compose:2.10.0")
    implementation("androidx.navigation:navigation-compose:2.10.1")

    implementation("org.jetbrains.kotlinx:kotlinx-coroutines-android:1.10.2")
    implementation("org.jetbrains.kotlinx:kotlinx-serialization-json:1.9.0")
    implementation("com.squareup.okhttp3:okhttp:5.5.0")
    implementation("io.coil-kt.coil3:coil-compose:3.6.2")
    implementation("io.coil-kt.coil3:coil-network-okhttp:3.6.2")
    implementation("org.maplibre.gl:android-sdk-opengl:13.6.1")
    implementation("com.google.zxing:core:3.5.4")

    testImplementation("junit:junit:4.13.2")
    testImplementation("org.jetbrains.kotlinx:kotlinx-coroutines-test:1.10.2")
    androidTestImplementation("androidx.test.ext:junit:1.3.0")
    androidTestImplementation("androidx.test:core:1.7.0")
    androidTestImplementation("androidx.test.espresso:espresso-core:3.7.0")
    androidTestImplementation("androidx.compose.ui:ui-test-junit4")
    debugImplementation("androidx.compose.ui:ui-tooling")
    debugImplementation("androidx.compose.ui:ui-test-manifest")
}
