import Firebase
import Flutter
import UIKit

@main
@objc class AppDelegate: FlutterAppDelegate {
  override func application(
    _ application: UIApplication,
    didFinishLaunchingWithOptions launchOptions: [UIApplication.LaunchOptionsKey: Any]?
  ) -> Bool {
    FirebaseApp.configure()

    let controller = window?.rootViewController as! FlutterViewController
    let channel = FlutterMethodChannel(
      name: "screenshot_guard",
      binaryMessenger: controller.binaryMessenger
    )
    channel.setMethodCallHandler { [weak self] call, result in
      guard let self = self else { return }
      switch call.method {
      case "enable":
        self.window?.makeSecure(true)
        result(nil)
      case "disable":
        self.window?.makeSecure(false)
        result(nil)
      default:
        result(FlutterMethodNotImplemented)
      }
    }

    GeneratedPluginRegistrant.register(with: self)
    return super.application(application, didFinishLaunchingWithOptions: launchOptions)
  }
}

private extension UIWindow {
  func makeSecure(_ secure: Bool) {
    if secure {
      let field = UITextField()
      field.isSecureTextEntry = true
      addSubview(field)
      layer.superlayer?.addSublayer(field.layer)
      field.layer.sublayers?.first?.addSublayer(layer)
    } else {
      subviews.filter { $0 is UITextField }.forEach { $0.removeFromSuperview() }
    }
  }
}
