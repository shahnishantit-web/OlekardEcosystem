import UIKit
import Flutter
import FirebaseCore

@UIApplicationMain
@objc class AppDelegate: FlutterAppDelegate {
    override func application(
        _ application: UIApplication,
        didFinishLaunchingWithOptions launchOptions: [UIApplication.LaunchOptionsKey: Any]?
    ) -> Bool {
        FirebaseApp.configure()
        GeneratedPluginRegistrant.register(with: self)

        guard let controller = window?.rootViewController as? FlutterViewController else {
            return super.application(application, didFinishLaunchingWithOptions: launchOptions)
        }

        let screenshotGuardChannel = FlutterMethodChannel(
            name: "screenshot_guard",
            binaryMessenger: controller.binaryMessenger
        )
        screenshotGuardChannel.setMethodCallHandler { (call, result) in
            switch call.method {
            case "enable":
                // Add a secure overlay to prevent screenshots.
                let secureField = UITextField()
                secureField.isSecureTextEntry = true
                if let window = UIApplication.shared.windows.first {
                    window.makeKeyAndVisible()
                    secureField.frame = window.frame
                    window.addSubview(secureField)
                    window.layer.superlayer?.addSublayer(secureField.layer)
                    secureField.layer.sublayers?.first?.addSublayer(window.layer)
                }
                result(nil)
            case "disable":
                // Remove the secure overlay.
                if let window = UIApplication.shared.windows.first {
                    window.subviews
                        .filter { $0 is UITextField }
                        .forEach { $0.removeFromSuperview() }
                }
                result(nil)
            default:
                result(FlutterMethodNotImplemented)
            }
        }

        return super.application(application, didFinishLaunchingWithOptions: launchOptions)
    }
}
