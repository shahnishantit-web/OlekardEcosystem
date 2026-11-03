buildscript {
    repositories {
        google()
        mavenCentral()
    }
    dependencies {
        classpath("com.google.gms:google-services:4.4.1")
    }
}

allprojects {
    repositories {
        google()
        mavenCentral()
    }
}

rootProject.buildDir = file("../build")
subprojects {
    project.buildDir = file("${rootProject.buildDir}/${project.name}")
}

// printing 5.13.1 pins its Android library compileSdk to 30, while its
// resolved AndroidX artifacts require API 34+. Apply a narrow compileSdk
// override after the plugin's Android block, before AGP finalizes the project.
project(":printing").afterEvaluate {
    val androidExtension = extensions.findByName("android")
        ?: throw GradleException("The printing Android extension was not configured.")
    val compileSdkMethod = androidExtension.javaClass.methods.firstOrNull { method ->
        method.name in setOf("setCompileSdk", "setCompileSdkVersion", "compileSdkVersion") &&
            method.parameterCount == 1 &&
            method.parameterTypes[0] in setOf(Int::class.javaPrimitiveType, String::class.java)
    } ?: throw GradleException("Could not set printing's Android compileSdk to match the app.")
    val compileSdkValue: Any = if (compileSdkMethod.parameterTypes[0] == String::class.java) {
        "android-36"
    } else {
        36
    }
    compileSdkMethod.invoke(androidExtension, compileSdkValue)
}

subprojects {
    project.evaluationDependsOn(":app")
}

tasks.register<Delete>("clean") {
    delete(rootProject.buildDir)
}
